<?php

namespace InstagramAPI;

use Curl\Curl;

class HttpInterface
{
    protected $parent;
    protected $userAgent;
    protected $verifyPeer = true;
    protected $verifyHost = 2;
    public $proxy = [];
    public $outputInterface;

    /**
     * @var Curl Curl Object
     */
    private $curl;

    public function __construct($parent)
    {
        $this->parent = $parent;
        $this->userAgent = $this->parent->settings->get('user_agent');
    }

    public function request($endpoint, $post = null, $login = false, $flood_wait = false, $assoc = true)
    {
        $this->curl = new Curl();

        if (!$this->parent->isLoggedIn && !$login) {
            throw new InstagramException("User is not logged in - login() must be called before making login-enforced requests.\n", ErrorCode::INTERNAL_LOGIN_REQUIRED);
        }

        $this->curl->setUserAgent($this->userAgent);
        $this->curl->setHeader('Connection', 'close');
        $this->curl->setHeader('Accept', '*/*');
        $this->curl->setHeader('Accept-Encoding', Constants::ACCEPT_ENCODING);
        $this->curl->setHeader('X-IG-Capabilities', Constants::X_IG_Capabilities);
        $this->curl->setHeader('X-IG-Connection-Type', Constants::X_IG_Connection_Type);
        $this->curl->setHeader('X-IG-Connection-Speed', mt_rand(1000, 3700).'kbps');
        $this->curl->setHeader('X-FB-HTTP-Engine', Constants::X_FB_HTTP_Engine);
        $this->curl->setHeader('Content-Type', Constants::CONTENT_TYPE);
        $this->curl->setHeader('Accept-Language', Constants::ACCEPT_LANGUAGE);
        $this->curl->setOpt(CURLOPT_FOLLOWLOCATION, true);
        $this->curl->setOpt(CURLOPT_SSL_VERIFYPEER, $this->verifyPeer);
        $this->curl->setOpt(CURLOPT_SSL_VERIFYHOST, $this->verifyHost);

        if ($this->parent->settingsAdapter['type'] == 'file') {
            $this->curl->setCookieFile($this->parent->settings->cookiesPath);
            $this->curl->setCookieJar($this->parent->settings->cookiesPath);
        } else {
            $cookieJar = $this->parent->settings->get('cookies');
            $cookieJarFile = tempnam(sys_get_temp_dir(), uniqid('_instagram_cookie'));

            file_put_contents($cookieJarFile, $cookieJar);

            $this->curl->setCookieFile($cookieJarFile);
            $this->curl->setCookieJar($cookieJar);
        }

        if ($this->proxy) {
            $this->curl->setOpt(CURLOPT_HTTPPROXYTUNNEL, 1);
            $this->curl->setOpt(CURLOPT_PROXY, $this->proxy['host'].':'.$this->proxy['port']);
            if ($this->proxy['username']) {
                $this->curl->setOpt(CURLOPT_PROXYUSERPWD, $this->proxy['username'].':'.$this->proxy['password']);
            }
        }

        if ($this->outputInterface) {
            $this->curl->setOpt(CURLOPT_INTERFACE, $this->outputInterface);
        }

        if ($post) {
            $this->curl->post(Constants::API_URL.$endpoint, $post);
        } else {
            $this->curl->get(Constants::API_URL.$endpoint);
        }

        $header = $this->curl->responseHeaders;
        if (is_object($this->curl->response)) {
            $body = $this->curl->response;
        } else {
            $body = json_decode(gzdecode($this->curl->response));
        }
        $httpCode = $this->curl->getInfo(CURLINFO_HTTP_CODE);

        if ($this->parent->debug) {
            if ($post) {
                Debug::printRequest('POST', $endpoint);
            } else {
                Debug::printRequest('GET', $endpoint);
            }
            if ((!is_null($post) && (!is_array($post)))) {
                Debug::printPostData($post);
            }
            $bytes = Utils::formatBytes($this->curl->getInfo(CURLINFO_SIZE_DOWNLOAD));

            Debug::printHttpCode($httpCode, $bytes);
            Debug::printResponse(json_encode($body), $this->parent->truncatedDebug);
        }

        $this->curl->close();

        if ($this->parent->settingsAdapter['type'] == 'mysql') {
            $newCookies = file_get_contents($cookieJarFile);
            $this->parent->settings->set('cookies', $newCookies);
        } elseif ($this->parent->settings->setting instanceof SettingsAdapter\SettingsInterface) {
            $newCookies = file_get_contents($cookieJarFile);
            $this->parent->settings->set('cookies', $newCookies);
        }

        if ($httpCode == 429 && $flood_wait) {
            if ($this->parent->debug) {
                echo "Too many requests! Sleeping 2s\n";
            }
            sleep(2);

            return $this->request($endpoint, $post, $login, false, $assoc);
        } else {
            return [$header, $body];
        }
    }

    public function getResponseWithResult($obj, $response)
    {
        if (is_null($response)) {
            throw new InstagramException('No response from server, connection or configure error', ErrorCode::EMPTY_RESPONSE);
        }

        $mapper = new \JsonMapper();

        $mapper->bStrictNullTypes = false;
        if (isset($_GET['debug'])) {
            $mapper->bExceptionOnUndefinedProperty = true;
        }

        $responseObject = $mapper->map($response, $obj);

        if (!$responseObject->isOk()) {
            throw new InstagramException(get_class($obj).' : '.$responseObject->getMessage());
        }
        $responseObject->setFullResponse($response);

        return $responseObject;
    }

    /**
     * @param $photo
     * @param null $caption
     * @param null $upload_id
     * @param null $customPreview
     * @param null $location
     * @param null $filter
     * @param bool $reel_flag
     *
     * @throws InstagramException
     */
    public function uploadPhoto($photo, $upload_id = null, $album = false)
    {
        $this->curl = new Curl();

        $endpoint = 'upload/photo/';
        $boundary = $this->parent->uuid;
        //$helper = new AdaptImage();

        if (!is_null($upload_id)) {
            $fileToUpload = Utils::createVideoIcon($photo);
        } else {
            $upload_id = Utils::generateUploadId();
            $fileToUpload = file_get_contents($photo);
        }

        $bodies = [
            [
                'type' => 'form-data',
                'name' => 'upload_id',
                'data' => $upload_id,
            ],
            [
                'type' => 'form-data',
                'name' => '_uuid',
                'data' => $this->parent->uuid,
            ],
            [
                'type' => 'form-data',
                'name' => '_csrftoken',
                'data' => $this->parent->token,
            ],
            [
                'type' => 'form-data',
                'name' => 'image_compression',
                'data' => '{"lib_name":"jt","lib_version":"1.3.0","quality":"87"}',
            ],
            [
                'type'     => 'form-data',
                'name'     => 'photo',
                'data'     => $fileToUpload,
                'filename' => 'pending_media_'.Utils::generateUploadId().'.jpg',
                'headers'  => [
                    'Content-Transfer-Encoding: binary',
                    'Content-Type: application/octet-stream',
                ],
            ],
        ];

        if ($album) {
            $bodies[] = [
                'type' => 'form-data',
                'name' => 'is_sidecar',
                'data' => '1',
            ];
        }

        $data = $this->buildBody($bodies, $boundary);

        $this->curl->setUserAgent($this->userAgent);
        $this->curl->setHeader('Connection', 'close');
        $this->curl->setHeader('Accept-Encoding', Constants::ACCEPT_ENCODING);
        $this->curl->setHeader('X-IG-Capabilities', Constants::X_IG_Capabilities);
        $this->curl->setHeader('X-IG-Connection-Type', Constants::X_IG_Connection_Type);
        $this->curl->setHeader('X-IG-Connection-Speed', mt_rand(1000, 3700).'kbps');
        $this->curl->setHeader('X-FB-HTTP-Engine', Constants::X_FB_HTTP_Engine);
        $this->curl->setHeader('Content-Type', Constants::CONTENT_TYPE);
        $this->curl->setHeader('Content-Length', strlen($data));
        $this->curl->setHeader('Content-Type', 'multipart/form-data; boundary='.$boundary);
        $this->curl->setHeader('Accept-Language', Constants::ACCEPT_LANGUAGE);
        $this->curl->setOpt(CURLOPT_FOLLOWLOCATION, true);
        $this->curl->setOpt(CURLOPT_SSL_VERIFYPEER, $this->verifyPeer);
        $this->curl->setOpt(CURLOPT_SSL_VERIFYHOST, $this->verifyHost);

        if ($this->parent->settingsAdapter['type'] == 'file') {
            $this->curl->setCookieFile($this->parent->settings->cookiesPath);
            $this->curl->setCookieJar($this->parent->settings->cookiesPath);
        } else {
            $cookieJar = $this->parent->settings->get('cookies');
            $cookieJarFile = tempnam(sys_get_temp_dir(), uniqid('_instagram_cookie'));

            file_put_contents($cookieJarFile, $cookieJar);

            $this->curl->setCookieFile($cookieJarFile);
            $this->curl->setCookieJar($cookieJar);
        }

        if ($this->proxy) {
            $this->curl->setOpt(CURLOPT_HTTPPROXYTUNNEL, 1);
            $this->curl->setOpt(CURLOPT_PROXY, $this->proxy['host'].':'.$this->proxy['port']);
            if ($this->proxy['username']) {
                $this->curl->setOpt(CURLOPT_PROXYUSERPWD, $this->proxy['username'].':'.$this->proxy['password']);
            }
        }

        $this->curl->post(Constants::API_URL.$endpoint, $data);

        $header = $this->curl->head;
        if (is_object($this->curl->response)) {
            $body = $this->curl->response;
        } else {
            $body = json_decode(gzdecode($this->curl->response));
        }
        $httpCode = $this->curl->getInfo(CURLINFO_HTTP_CODE);

        $upload = $this->getResponseWithResult(new UploadPhotoResponse(), $body);

        if ($this->parent->debug) {
            Debug::printRequest('POST', $endpoint);

            $uploadBytes = Utils::formatBytes($this->curl->getInfo(CURLINFO_SIZE_UPLOAD));
            Debug::printUpload($uploadBytes);

            $bytes = Utils::formatBytes($this->curl->getInfo(CURLINFO_SIZE_DOWNLOAD));
            Debug::printHttpCode($httpCode, $bytes);
            Debug::printResponse(json_encode($body));
        }

        $this->curl->close();

        if ($this->parent->settingsAdapter['type'] == 'mysql') {
            $newCookies = file_get_contents($cookieJarFile);
            $this->parent->settings->set('cookies', $newCookies);
        } elseif ($this->parent->settings->setting instanceof SettingsAdapter\SettingsInterface) {
            $newCookies = file_get_contents($cookieJarFile);
            $this->parent->settings->set('cookies', $newCookies);
        }

        return $upload;
    }

    public function uploadVideo($video, $caption = null, $customPreview = null)
    {
        $videoData = file_get_contents($video);

        $endpoint = 'upload/video/';
        $boundary = $this->parent->uuid;
        $upload_id = Utils::generateUploadId();
        $bodies = [
            [
                'type' => 'form-data',
                'name' => 'upload_id',
                'data' => $upload_id,
            ],
            [
                'type' => 'form-data',
                'name' => '_csrftoken',
                'data' => $this->parent->token,
            ],
            [
                'type' => 'form-data',
                'name' => 'media_type',
                'data' => '2',
            ],
            [
                'type' => 'form-data',
                'name' => '_uuid',
                'data' => $this->parent->uuid,
            ],
        ];

        $data = $this->buildBody($bodies, $boundary);
        $headers = [
            'Connection: keep-alive',
            'Accept: */*',
            'Host: i.instagram.com',
            'Content-Type: multipart/form-data; boundary='.$boundary,
            'Accept-Language: en-en',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, Constants::API_URL.$endpoint);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_VERBOSE, false);
        curl_setopt($ch, CURLOPT_ENCODING, '');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($this->parent->settingsAdapter['type'] == 'file') {
            curl_setopt($ch, CURLOPT_COOKIEFILE, $this->parent->settings->cookiesPath);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $this->parent->settings->cookiesPath);
        } else {
            $cookieJar = $this->parent->settings->get('cookies');
            $cookieJarFile = tempnam(sys_get_temp_dir(), uniqid('_instagram_cookie'));

            file_put_contents($cookieJarFile, $cookieJar);

            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJarFile);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJarFile);
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

        if ($this->proxy) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy['host'].':'.$this->proxy['port']);
            if ($this->proxy['username']) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $this->proxy['username'].':'.$this->proxy['password']);
            }
        }

        $resp = curl_exec($ch);
        $header_len = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        $body = $this->getResponseWithResult(new UploadJobVideoResponse(), json_decode(substr($resp, $header_len)));
        $uploadUrl = $body->getVideoUploadUrls()[3]->url;
        $job = $body->getVideoUploadUrls()[3]->job;

        $request_size = floor(strlen($videoData) / 4);
        $lastRequestExtra = (strlen($videoData) - ($request_size * 4));

        if ($this->parent->debug) {
            Debug::printRequest('POST', $endpoint);

            $uploadBytes = Utils::formatBytes(curl_getinfo($ch, CURLINFO_SIZE_UPLOAD));
            Debug::printUpload($uploadBytes);

            $bytes = Utils::formatBytes(curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD));
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            Debug::printHttpCode($httpCode, $bytes);
            Debug::printResponse(substr($resp, $header_len));
        }

        for ($a = 0; $a <= 3; ++$a) {
            $start = ($a * $request_size);
            $end = ($a + 1) * $request_size + ($a == 3 ? $lastRequestExtra : 0);

            $headers = [
                'Connection: keep-alive',
                'Accept: */*',
                'Host: upload.instagram.com',
                'Cookie2: $Version=1',
                'Accept-Encoding: gzip, deflate',
                'Content-Type: application/octet-stream',
                'Session-ID: '.$upload_id,
                'Accept-Language: en-en',
                'Content-Disposition: attachment; filename="video.mov"',
                'Content-Length: '.($end - $start),
                'Content-Range: '.'bytes '.$start.'-'.($end - 1).'/'.strlen($videoData),
                'job: '.$job,
            ];

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $uploadUrl);
            curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_VERBOSE, false);
            curl_setopt($ch, CURLOPT_ENCODING, '');
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            if ($this->parent->settingsAdapter['type'] == 'file') {
                curl_setopt($ch, CURLOPT_COOKIEFILE, $this->parent->settings->cookiesPath);
                curl_setopt($ch, CURLOPT_COOKIEJAR, $this->parent->settings->cookiesPath);
            } else {
                $cookieJar = $this->parent->settings->get('cookies');
                $cookieJarFile = tempnam(sys_get_temp_dir(), uniqid('_instagram_cookie'));

                file_put_contents($cookieJarFile, $cookieJar);

                curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJarFile);
                curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJarFile);
            }
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, substr($videoData, $start, $end));

            if ($this->proxy) {
                curl_setopt($ch, CURLOPT_PROXY, $this->proxy['host'].':'.$this->proxy['port']);
                if ($this->proxy['username']) {
                    curl_setopt($ch, CURLOPT_PROXYUSERPWD, $this->proxy['username'].':'.$this->proxy['password']);
                }
            }

            $result = curl_exec($ch);
            $header_len = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $body = substr($result, $header_len);
            $array[] = [$body];

            if ($this->parent->debug) {
                Debug::printRequest('POST', $uploadUrl);

                $uploadBytes = Utils::formatBytes(curl_getinfo($ch, CURLINFO_SIZE_UPLOAD));
                Debug::printUpload($uploadBytes);

                $bytes = Utils::formatBytes(curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD));
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                Debug::printHttpCode($httpCode, $bytes);
                Debug::printResponse($body);
            }
        }
        $resp = curl_exec($ch);
        $header_len = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($resp, 0, $header_len);

        /*
        $upload = $this->getResponseWithResult(new UploadVideoResponse(), json_decode(substr($resp, $header_len)));

        if (!is_null($upload->getMessage())) {
            throw new InstagramException($upload->getMessage()."\n");

            return;
        }
        */

        if ($this->parent->debug) {
            Debug::printRequest('POST', $endpoint);

            $bytes = Utils::formatBytes(curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD));
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            Debug::printHttpCode($httpCode, $bytes);
            Debug::printResponse(substr($resp, $header_len), $this->parent->truncatedDebug);
        }

        curl_close($ch);
        if ($this->parent->settingsAdapter['type'] == 'mysql') {
            $newCookies = file_get_contents($cookieJarFile);
            $this->parent->settings->set('cookies', $newCookies);
        } elseif ($this->parent->settings->setting instanceof SettingsAdapter\SettingsInterface) {
            $newCookies = file_get_contents($cookieJarFile);
            $this->parent->settings->set('cookies', $newCookies);
        }
        $configure = $this->parent->configureVideo($upload_id, $video, $caption, $customPreview);
        //$this->parent->expose();
        $attemps = 0;
        while ($configure->getMessage() == 'Transcode timeout' && $attemps < 3) {
            sleep(1);
            $configure = $this->parent->configureVideo($upload_id, $video, $caption, $customPreview);
            $attemps++;
        }

        return $configure;
    }

    public function changeProfilePicture($photo)
    {
        if (is_null($photo)) {
            echo "Photo not valid\n\n";

            return;
        }

        $uData = json_encode([
            '_csrftoken' => $this->parent->token,
            '_uuid'      => $this->parent->uuid,
            '_uid'       => $this->parent->username_id,
        ]);

        $endpoint = 'accounts/change_profile_picture/';
        $boundary = $this->parent->uuid;
        $bodies = [
            [
                'type' => 'form-data',
                'name' => 'ig_sig_key_version',
                'data' => Constants::SIG_KEY_VERSION,
            ],
            [
                'type' => 'form-data',
                'name' => 'signed_body',
                'data' => hash_hmac('sha256', $uData, Constants::IG_SIG_KEY).$uData,
            ],
            [
                'type'     => 'form-data',
                'name'     => 'profile_pic',
                'data'     => file_get_contents($photo),
                'filename' => 'profile_pic',
                'headers'  => [
                    'Content-Type: application/octet-stream',
                    'Content-Transfer-Encoding: binary',
                ],
            ],
        ];

        $data = $this->buildBody($bodies, $boundary);
        $headers = [
            'Proxy-Connection: keep-alive',
            'Connection: keep-alive',
            'Accept: */*',
            'Content-Type: multipart/form-data; boundary='.$boundary,
            'Accept-Language: en-en',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, Constants::API_URL.$endpoint);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_VERBOSE, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifyPeer);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifyHost);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($this->parent->settingsAdapter['type'] == 'file') {
            curl_setopt($ch, CURLOPT_COOKIEFILE, $this->parent->settings->cookiesPath);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $this->parent->settings->cookiesPath);
        } else {
            $cookieJar = $this->parent->settings->get('cookies');
            $cookieJarFile = tempnam(sys_get_temp_dir(), uniqid('_instagram_cookie'));

            file_put_contents($cookieJarFile, $cookieJar);

            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJarFile);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJarFile);
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

        if ($this->proxy) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy['host'].':'.$this->proxy['port']);
            if ($this->proxy['username']) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $this->proxy['username'].':'.$this->proxy['password']);
            }
        }

        $resp = curl_exec($ch);
        $header_len = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($resp, 0, $header_len);
        $upload = json_decode(substr($resp, $header_len), true);

        if ($this->parent->debug) {
            Debug::printRequest('POST', $endpoint);

            $uploadBytes = Utils::formatBytes(curl_getinfo($ch, CURLINFO_SIZE_UPLOAD));
            Debug::printUpload($uploadBytes);

            $bytes = Utils::formatBytes(curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD));
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            Debug::printHttpCode($httpCode, $bytes);
            Debug::printResponse(substr($resp, $header_len));
        }

        curl_close($ch);
        if ($this->parent->settingsAdapter['type'] == 'mysql') {
            $newCookies = file_get_contents($cookieJarFile);
            $this->parent->settings->set('cookies', $newCookies);
        } elseif ($this->parent->settings->setting instanceof SettingsAdapter\SettingsInterface) {
            $newCookies = file_get_contents($cookieJarFile);
            $this->parent->settings->set('cookies', $newCookies);
        }
    }

    public function direct_share($media_id, $recipients, $text = null)
    {
        if (!is_array($recipients)) {
            $recipients = [$recipients];
        }

        $string = [];
        foreach ($recipients as $recipient) {
            $string[] = "\"$recipient\"";
        }

        $recipient_users = implode(',', $string);

        $endpoint = 'direct_v2/threads/broadcast/media_share/?media_type=photo';
        $boundary = $this->parent->uuid;
        $bodies = [
            [
                'type' => 'form-data',
                'name' => 'media_id',
                'data' => $media_id,
            ],
            [
                'type' => 'form-data',
                'name' => 'recipient_users',
                'data' => "[[$recipient_users]]",
            ],
            [
                'type' => 'form-data',
                'name' => 'client_context',
                'data' => $this->parent->uuid,
            ],
            [
                'type' => 'form-data',
                'name' => 'thread_ids',
                'data' => '["0"]',
            ],
            [
                'type' => 'form-data',
                'name' => 'text',
                'data' => is_null($text) ? '' : $text,
            ],
        ];

        $data = $this->buildBody($bodies, $boundary);
        $headers = [
            'Proxy-Connection: keep-alive',
            'Connection: keep-alive',
            'Accept: */*',
            'Content-Type: multipart/form-data; boundary='.$boundary,
            'Accept-Language: en-en',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, Constants::API_URL.$endpoint);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_VERBOSE, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifyPeer);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifyHost);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($this->parent->settingsAdapter['type'] == 'file') {
            curl_setopt($ch, CURLOPT_COOKIEFILE, $this->parent->settings->cookiesPath);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $this->parent->settings->cookiesPath);
        } else {
            $cookieJar = $this->parent->settings->get('cookies');
            $cookieJarFile = tempnam(sys_get_temp_dir(), uniqid('_instagram_cookie'));

            file_put_contents($cookieJarFile, $cookieJar);

            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJarFile);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJarFile);
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

        if ($this->proxy) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy['host'].':'.$this->proxy['port']);
            if ($this->proxy['username']) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $this->proxy['username'].':'.$this->proxy['password']);
            }
        }

        $resp = curl_exec($ch);
        $header_len = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($resp, 0, $header_len);
        $upload = json_decode(substr($resp, $header_len), true);

        if ($this->parent->debug) {
            Debug::printRequest('POST', $endpoint);

            $uploadBytes = Utils::formatBytes(curl_getinfo($ch, CURLINFO_SIZE_UPLOAD));
            Debug::printUpload($uploadBytes);

            $bytes = Utils::formatBytes(curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD));
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            Debug::printHttpCode($httpCode, $bytes);
            Debug::printResponse(substr($resp, $header_len));
        }

        curl_close($ch);
        if ($this->parent->settingsAdapter['type'] == 'mysql') {
            $newCookies = file_get_contents($cookieJarFile);
            $this->parent->settings->set('cookies', $newCookies);
        } elseif ($this->parent->settings->setting instanceof SettingsAdapter\SettingsInterface) {
            $newCookies = file_get_contents($cookieJarFile);
            $this->parent->settings->set('cookies', $newCookies);
        }
    }

    public function direct_message($recipients, $text)
    {
        if (!is_array($recipients)) {
            $recipients = [$recipients];
        }

        $string = [];
        foreach ($recipients as $recipient) {
            $string[] = "\"$recipient\"";
        }

        $recipient_users = implode(',', $string);

        $endpoint = 'direct_v2/threads/broadcast/text/';
        $boundary = $this->parent->uuid;
        $bodies = [
            [
                'type' => 'form-data',
                'name' => 'recipient_users',
                'data' => "[[$recipient_users]]",
            ],
            [
                'type' => 'form-data',
                'name' => 'client_context',
                'data' => $this->parent->uuid,
            ],
            [
                'type' => 'form-data',
                'name' => 'thread_ids',
                'data' => '["0"]',
            ],
            [
                'type' => 'form-data',
                'name' => 'text',
                'data' => is_null($text) ? '' : $text,
            ],
        ];

        $data = $this->buildBody($bodies, $boundary);
        $headers = [
            'Proxy-Connection: keep-alive',
            'Connection: keep-alive',
            'Accept: */*',
            'Content-Type: multipart/form-data; boundary='.$boundary,
            'Accept-Language: en-en',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, Constants::API_URL.$endpoint);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_VERBOSE, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifyPeer);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifyHost);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($this->parent->settingsAdapter['type'] == 'file') {
            curl_setopt($ch, CURLOPT_COOKIEFILE, $this->parent->settings->cookiesPath);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $this->parent->settings->cookiesPath);
        } else {
            $cookieJar = $this->parent->settings->get('cookies');
            $cookieJarFile = tempnam(sys_get_temp_dir(), uniqid('_instagram_cookie'));

            file_put_contents($cookieJarFile, $cookieJar);

            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJarFile);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJarFile);
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

        if ($this->proxy) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy['host'].':'.$this->proxy['port']);
            if ($this->proxy['username']) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $this->proxy['username'].':'.$this->proxy['password']);
            }
        }

        $resp = curl_exec($ch);
        $header_len = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($resp, 0, $header_len);
        $upload = $this->getResponseWithResult(new Response(), json_decode(substr($resp, $header_len)));

        if (!$upload->isOk()) {
            throw new InstagramException($upload->getMessage());
            return;
        }

        if ($this->parent->debug) {
            Debug::printRequest('POST', $endpoint);

            $uploadBytes = Utils::formatBytes(curl_getinfo($ch, CURLINFO_SIZE_UPLOAD));
            Debug::printUpload($uploadBytes);

            $bytes = Utils::formatBytes(curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD));
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            Debug::printHttpCode($httpCode, $bytes);
            Debug::printResponse(substr($resp, $header_len));
        }

        curl_close($ch);
        if ($this->parent->settingsAdapter['type'] == 'mysql') {
            $newCookies = file_get_contents($cookieJarFile);
            $this->parent->settings->set('cookies', $newCookies);
        } elseif ($this->parent->settings->setting instanceof SettingsAdapter\SettingsInterface) {
            $newCookies = file_get_contents($cookieJarFile);
            $this->parent->settings->set('cookies', $newCookies);
        }
    }

    public function direct_photo($recipients, $filepath, $text)
    {
        if (!is_array($recipients)) {
            $recipients = [$recipients];
        }

        $string = [];
        foreach ($recipients as $recipient) {
            $string[] = "\"$recipient\"";
        }

        $recipient_users = implode(',', $string);

        $endpoint = 'direct_v2/threads/broadcast/upload_photo/';
        $boundary = $this->parent->uuid;
        $photo = file_get_contents($filepath);

        $bodies = [
            [
                'type' => 'form-data',
                'name' => 'recipient_users',
                'data' => "[[$recipient_users]]",
            ],
            [
                'type' => 'form-data',
                'name' => 'client_context',
                'data' => $this->parent->uuid,
            ],
            [
                'type' => 'form-data',
                'name' => 'thread_ids',
                'data' => '["0"]',
            ],
            [
                'type'     => 'form-data',
                'name'     => 'photo',
                'data'     => $photo,
                'filename' => 'photo',
                'headers'  => [
                    'Content-Type: '.mime_content_type($filepath),
                    'Content-Transfer-Encoding: binary',
                ],
            ],
            [
                'type' => 'form-data',
                'name' => 'text',
                'data' => is_null($text) ? '' : $text,
            ],
        ];

        $data = $this->buildBody($bodies, $boundary);
        $headers = [
            'Proxy-Connection: keep-alive',
            'Connection: keep-alive',
            'Accept: */*',
            'Content-Type: multipart/form-data; boundary='.$boundary,
            'Content-Length: '.strlen($data),
            'Connection: keep-alive',
            'Accept-Language: en-en',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, Constants::API_URL.$endpoint);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->userAgent);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_VERBOSE, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifyPeer);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifyHost);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if ($this->parent->settingsAdapter['type'] == 'file') {
            curl_setopt($ch, CURLOPT_COOKIEFILE, $this->parent->settings->cookiesPath);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $this->parent->settings->cookiesPath);
        } else {
            $cookieJar = $this->parent->settings->get('cookies');
            $cookieJarFile = tempnam(sys_get_temp_dir(), uniqid('_instagram_cookie'));

            file_put_contents($cookieJarFile, $cookieJar);

            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJarFile);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJarFile);
        }
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

        if ($this->proxy) {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxy['host'].':'.$this->proxy['port']);
            if ($this->proxy['username']) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $this->proxy['username'].':'.$this->proxy['password']);
            }
        }

        $resp = curl_exec($ch);
        $header_len = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($resp, 0, $header_len);
        $upload = $this->getResponseWithResult(new Response(), json_decode(substr($resp, $header_len)));

        if (!$upload->isOk()) {
            throw new InstagramException($upload->getMessage());
        }

        if ($this->parent->debug) {
            Debug::printRequest('POST', $endpoint);

            $uploadBytes = Utils::formatBytes(curl_getinfo($ch, CURLINFO_SIZE_UPLOAD));
            Debug::printUpload($uploadBytes);

            $bytes = Utils::formatBytes(curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD));
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            Debug::printHttpCode($httpCode, $bytes);
            Debug::printResponse(substr($resp, $header_len));
        }

        curl_close($ch);
        if ($this->parent->settingsAdapter['type'] == 'mysql') {
            $newCookies = file_get_contents($cookieJarFile);
            $this->parent->settings->set('cookies', $newCookies);
        } elseif ($this->parent->settings->setting instanceof SettingsAdapter\SettingsInterface) {
            $newCookies = file_get_contents($cookieJarFile);
            $this->parent->settings->set('cookies', $newCookies);
        }
    }

    protected function buildBody($bodies, $boundary)
    {
        $body = '';
        foreach ($bodies as $b) {
            $body .= '--'.$boundary."\r\n";
            $body .= 'Content-Disposition: '.$b['type'].'; name="'.$b['name'].'"';
            if (isset($b['filename'])) {
                $ext = pathinfo($b['filename'], PATHINFO_EXTENSION);
                $body .= '; filename="'.'pending_media_'.Utils::generateUploadId().'.'.$ext.'"';
            }
            if (isset($b['headers']) && is_array($b['headers'])) {
                foreach ($b['headers'] as $header) {
                    $body .= "\r\n".$header;
                }
            }

            $body .= "\r\n\r\n".$b['data']."\r\n";
        }
        $body .= '--'.$boundary.'--';

        return $body;
    }

    public function verifyPeer($enable)
    {
        $this->verifyPeer = $enable;
    }

    public function verifyHost($enable)
    {
        $this->verifyHost = $enable;
    }
}
