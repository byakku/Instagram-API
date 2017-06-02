<?php

namespace InstagramAPI\Request;

use InstagramAPI\Constants;
use InstagramAPI\Response;
use InstagramAPI\Signatures;
use InstagramAPI\Utils;

/**
 * Collection of various INTERNAL library functions.
 *
 * THESE FUNCTIONS ARE NOT FOR PUBLIC USE!
 */
class Internal extends RequestCollection
{
    /**
     * UPLOADS A *SINGLE* PHOTO.
     *
     * @param string $targetFeed       Target feed for this media ("timeline", "story",
     *                                 but NOT "album", they are handled elsewhere).
     * @param string $photoFilename    The photo filename.
     * @param array  $externalMetadata (optional) User-provided metadata key-value pairs.
     *
     * @throws \InvalidArgumentException
     * @throws \InstagramAPI\Exception\InstagramException
     *
     * @return \InstagramAPI\Response\ConfigureResponse
     *
     * @see Internal::configureSinglePhoto() for available metadata fields.
     */
    public function uploadSinglePhoto(
        $targetFeed,
        $photoFilename,
        array $externalMetadata = [])
    {
        // Make sure we only allow these particular feeds for this function.
        if ($targetFeed != 'timeline' && $targetFeed != 'story') {
            throw new \InvalidArgumentException(sprintf('Bad target feed "%s".', $targetFeed));
        }

        // Verify that the file exists locally.
        if (!is_file($photoFilename)) {
            throw new \InvalidArgumentException(sprintf('The photo file "%s" does not exist on disk.', $photoFilename));
        }

        // Determine the width and height of the photo.
        $imagesize = @getimagesize($photoFilename);
        if ($imagesize === false) {
            throw new \InvalidArgumentException(sprintf('File "%s" is not an image.', $photoFilename));
        }
        list($photoWidth, $photoHeight) = $imagesize;

        // Validate image resolution and aspect ratio.
        Utils::throwIfIllegalMediaResolution($targetFeed, 'photofile', $photoFilename, $photoWidth, $photoHeight);

        // Perform the upload.
        $upload = $this->uploadPhotoData($targetFeed, $photoFilename);

        // Configure the uploaded image and attach it to our timeline/story.
        $internalMetadata = [
            'uploadId'      => $upload->getUploadId(),
            'photoWidth'    => $photoWidth,
            'photoHeight'   => $photoHeight,
        ];
        $configure = $this->configureSinglePhoto($targetFeed, $internalMetadata, $externalMetadata);

        return $configure;
    }

    /**
     * Upload the data for a photo to Instagram.
     *
     * @param string $targetFeed    Target feed for this media ("timeline", "story" or "album").
     * @param string $photoFilename The photo filename.
     * @param string $fileType      Whether the file is a "photofile" or "videofile".
     *                              In case of videofile we'll generate a thumbnail from it.
     * @param null   $uploadId      Custom upload ID if wanted. Otherwise autogenerated.
     *
     * @throws \InvalidArgumentException
     * @throws \InstagramAPI\Exception\InstagramException
     *
     * @return \InstagramAPI\Response\UploadPhotoResponse
     */
    public function uploadPhotoData(
        $targetFeed,
        $photoFilename,
        $fileType = 'photofile',
        $uploadId = null)
    {
        // Verify that the file exists locally.
        if (!is_file($photoFilename)) {
            throw new \InvalidArgumentException(sprintf('The photo file "%s" does not exist on disk.', $photoFilename));
        }

        // Determine which file contents to upload.
        if ($fileType == 'videofile') {
            // Generate a thumbnail from a video file.
            $photoData = Utils::createVideoIcon($photoFilename);
        } else {
            $photoData = file_get_contents($photoFilename);
        }

        // Generate an upload ID if none was provided.
        if (is_null($uploadId)) {
            $uploadId = Utils::generateUploadId();
        }

        // Prepare payload for the upload request.
        $request = $this->ig->request('upload/photo/')
            ->setSignedPost(false)
            ->addPost('upload_id', $uploadId)
            ->addPost('_uuid', $this->ig->uuid)
            ->addPost('_csrftoken', $this->ig->client->getToken())
            ->addPost('image_compression', '{"lib_name":"jt","lib_version":"1.3.0","quality":"87"}')
            ->addFileData('photo', $photoData, 'pending_media_'.Utils::generateUploadId().'.jpg');

        if ($targetFeed == 'album') {
            $request->addPost('is_sidecar', '1');
            if ($fileType == 'videofile') {
                $request->addPost('media_type', '2');
            }
        }

        return $request->getResponse(new Response\UploadPhotoResponse());
    }

    /**
     * Configures parameters for a *SINGLE* uploaded photo file.
     *
     * WARNING TO CONTRIBUTORS: THIS IS ONLY FOR *TIMELINE* AND *STORY* -PHOTOS-.
     * USE "configureTimelineAlbum()" FOR ALBUMS and "configureSingleVideo()" FOR VIDEOS.
     * AND IF FUTURE INSTAGRAM FEATURES NEED CONFIGURATION AND ARE NON-TRIVIAL,
     * GIVE THEM THEIR OWN FUNCTION LIKE WE DID WITH "configureTimelineAlbum()",
     * TO AVOID ADDING BUGGY AND UNMAINTAINABLE SPIDERWEB CODE!
     *
     * @param string $targetFeed       Target feed for this media ("timeline", "story",
     *                                 but NOT "album", they are handled elsewhere).
     * @param array  $internalMetadata Internal library-generated metadata key-value pairs.
     * @param array  $externalMetadata (optional) User-provided metadata key-value pairs.
     *
     * @throws \InvalidArgumentException
     * @throws \InstagramAPI\Exception\InstagramException
     *
     * @return \InstagramAPI\Response\ConfigureResponse
     */
    public function configureSinglePhoto(
        $targetFeed,
        array $internalMetadata,
        array $externalMetadata = [])
    {
        // Determine the target endpoint for the photo.
        switch ($targetFeed) {
        case 'timeline':
            $endpoint = 'media/configure/';
            break;
        case 'story':
            $endpoint = 'media/configure_to_story/';
            break;
        default:
            throw new \InvalidArgumentException(sprintf('Bad target feed "%s".', $targetFeed));
        }

        // Available external metadata parameters:
        /** @var string|null Caption to use for the media. NOT USED FOR STORY MEDIA! */
        $captionText = isset($externalMetadata['caption']) ? $externalMetadata['caption'] : null;
        /** @var Response\Model\Location|null A Location object describing where
         the media was taken. NOT USED FOR STORY MEDIA! */
        $location = (isset($externalMetadata['location']) && $targetFeed != 'story') ? $externalMetadata['location'] : null;
        /** @var void Photo filter. THIS DOES NOTHING! All real filters are done in the mobile app. */
        // $filter = isset($externalMetadata['filter']) ? $externalMetadata['filter'] : null;
        $filter = null; // COMMENTED OUT SO USERS UNDERSTAND THEY CAN'T USE THIS!

        // Fix very bad external user-metadata values.
        if (!is_string($captionText)) {
            $captionText = '';
        }

        // Critically important internal library-generated metadata parameters:
        /** @var string The ID of the entry to configure. */
        $uploadId = $internalMetadata['uploadId'];
        /** @var int|float Width of the photo. */
        $photoWidth = $internalMetadata['photoWidth'];
        /** @var int|float Height of the photo. */
        $photoHeight = $internalMetadata['photoHeight'];

        // Build the request...
        $request = $this->ig->request($endpoint)
            ->addPost('_csrftoken', $this->ig->client->getToken())
            ->addPost('_uid', $this->ig->account_id)
            ->addPost('_uuid', $this->ig->uuid)
            ->addPost('edits',
                [
                    'crop_original_size'    => [$photoWidth, $photoHeight],
                    'crop_zoom'             => 1,
                    'crop_center'           => [0.0, -0.0],
                ])
            ->addPost('device',
                [
                    'manufacturer'      => $this->ig->device->getManufacturer(),
                    'model'             => $this->ig->device->getModel(),
                    'android_version'   => $this->ig->device->getAndroidVersion(),
                    'android_release'   => $this->ig->device->getAndroidRelease(),
                ])
            ->addPost('extra',
                [
                    'source_width'  => $photoWidth,
                    'source_height' => $photoHeight,
                ]);

        switch ($targetFeed) {
            case 'timeline':
                $request
                    ->addPost('caption', $captionText)
                    ->addPost('source_type', 4)
                    ->addPost('media_folder', 'Camera')
                    ->addPost('upload_id', $uploadId);
                break;
            case 'story':
                $request
                    ->addPost('client_shared_at', time())
                    ->addPost('source_type', 3)
                    ->addPost('configure_mode', 1)
                    ->addPost('client_timestamp', time())
                    ->addPost('upload_id', $uploadId);
                break;
        }

        if ($location instanceof Response\Model\Location) {
            $loc = [
                $location->getExternalIdSource().'_id'   => $location->getExternalId(),
                'name'                                   => $location->getName(),
                'lat'                                    => $location->getLat(),
                'lng'                                    => $location->getLng(),
                'address'                                => $location->getAddress(),
                'external_source'                        => $location->getExternalIdSource(),
            ];

            $request
                ->addPost('location', json_encode($loc))
                ->addPost('geotag_enabled', '1')
                ->addPost('posting_latitude', $location->getLat())
                ->addPost('posting_longitude', $location->getLng())
                ->addPost('media_latitude', $location->getLat())
                ->addPost('media_longitude', $location->getLng())
                ->addPost('av_latitude', 0.0)
                ->addPost('av_longitude', 0.0);
        }

        $configure = $request->getResponse(new Response\ConfigureResponse());

        return $configure;
    }

    /**
     * UPLOADS A *SINGLE* VIDEO.
     *
     * @param string $targetFeed       Target feed for this media ("timeline", "story",
     *                                 but NOT "album", they are handled elsewhere).
     * @param string $videoFilename    The video filename.
     * @param array  $externalMetadata (optional) User-provided metadata key-value pairs.
     * @param int    $maxAttempts      (optional) Total attempts to upload all chunks before throwing.
     *
     * @throws \InvalidArgumentException
     * @throws \InstagramAPI\Exception\InstagramException
     * @throws \InstagramAPI\Exception\UploadFailedException If the video upload fails.
     *
     * @return \InstagramAPI\Response\ConfigureResponse
     *
     * @see Internal::configureSingleVideo() for available metadata fields.
     */
    public function uploadSingleVideo(
        $targetFeed,
        $videoFilename,
        array $externalMetadata = [],
        $maxAttempts = 10)
    {
        // Make sure we only allow these particular feeds for this function.
        if ($targetFeed != 'timeline' && $targetFeed != 'story') {
            throw new \InvalidArgumentException(sprintf('Bad target feed "%s".', $targetFeed));
        }

        // We require at least 1 attempt, otherwise we can't do anything.
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('The maxAttempts parameter must be 1 or higher.');
        }

        // Verify that the file exists locally.
        if (!is_file($videoFilename)) {
            throw new \InvalidArgumentException(sprintf('The video file "%s" does not exist on disk.', $videoFilename));
        }

        $internalMetadata = [];

        // Figure out the video file details.
        // NOTE: We do this first, since it validates whether the video file is
        // valid and lets us avoid wasting time uploading totally invalid files!
        $internalMetadata['videoDetails'] = Utils::getVideoFileDetails($videoFilename);

        // Validate the video details and throw if Instagram won't allow it.
        Utils::throwIfIllegalVideoDetails($targetFeed, $videoFilename, $internalMetadata['videoDetails']);

        // Request parameters for uploading a new video.
        $uploadParams = $this->requestVideoUploadURL($targetFeed, $internalMetadata);
        $internalMetadata['uploadId'] = $uploadParams['uploadId'];

        // Attempt to upload the video data.
        $upload = $this->ig->client->uploadVideoChunks($targetFeed, $videoFilename, $uploadParams, $maxAttempts);

        // Attempt to upload the thumbnail, associated with our video's ID.
        $this->uploadPhotoData($targetFeed, $videoFilename, 'videofile', $uploadParams['uploadId']);

        // Configure the uploaded video and attach it to our timeline/story.
        $configure = $this->configureSingleVideoWithRetries($targetFeed, $internalMetadata, $externalMetadata);

        return $configure;
    }

    /**
     * Asks Instagram for parameters for uploading a new video.
     *
     * @param string $targetFeed       Target feed for this media ("timeline", "story", "album" or "direct_v2").
     * @param array  $internalMetadata (optional) Internal library-generated metadata key-value pairs.
     *
     * @throws \InstagramAPI\Exception\InstagramException If the request fails.
     *
     * @return array
     */
    public function requestVideoUploadURL(
        $targetFeed,
        array $internalMetadata = [])
    {
        $uploadId = Utils::generateUploadId();

        $request = $this->ig->request('upload/video/')
            ->setSignedPost(false)
            ->addPost('upload_id', $uploadId)
            ->addPost('_csrftoken', $this->ig->client->getToken())
            ->addPost('_uuid', $this->ig->uuid);

        // Critically important internal library-generated metadata parameters:
        if ($targetFeed == 'album') {
            // NOTE: NO INTERNAL DATA IS NEEDED HERE YET.
            $request->addPost('is_sidecar', 1);
        } else {
            // Get all of the INTERNAL metadata needed for non-album videos.
            /** @var array Video details array. */
            $videoDetails = $internalMetadata['videoDetails'];
            $request
                ->addPost('media_type', '2')
                // NOTE: ceil() is to round up and get rid of any MS decimals.
                ->addPost('upload_media_duration_ms', (int) ceil($videoDetails['duration'] * 1000))
                ->addPost('upload_media_width', $videoDetails['width'])
                ->addPost('upload_media_height', $videoDetails['height']);

            if ($targetFeed === 'direct_v2') {
                $cropX = mt_rand(0, 128);
                $cropY = mt_rand(0, 128);
                $request
                    ->addPost('upload_media_width', '0')
                    ->addPost('upload_media_height', '0')
                    ->addPost('direct_v2', '1')
                    ->addPost('hflip', 'false')
                    ->addPost('rotate', '0')
                    ->addPost('crop_rect', json_encode([
                        $cropX,
                        $cropY,
                        $cropX + $videoDetails['width'],
                        $cropY + $videoDetails['height'],
                    ]));
            }
        }

        // Perform the "pre-upload" API request.
        /** @var Response\UploadJobVideoResponse $response */
        $response = $request->getResponse(new Response\UploadJobVideoResponse());

        // Determine where their API wants us to upload the video file.
        return [
            'uploadId'  => $uploadId,
            'uploadUrl' => $response->getVideoUploadUrls()[3]->url,
            'job'       => $response->getVideoUploadUrls()[3]->job,
        ];
    }

    /**
     * Configures parameters for a *SINGLE* uploaded video file.
     *
     * WARNING TO CONTRIBUTORS: THIS IS ONLY FOR *TIMELINE* AND *STORY* -VIDEOS-.
     * USE "configureTimelineAlbum()" FOR ALBUMS and "configureSinglePhoto()" FOR PHOTOS.
     * AND IF FUTURE INSTAGRAM FEATURES NEED CONFIGURATION AND ARE NON-TRIVIAL,
     * GIVE THEM THEIR OWN FUNCTION LIKE WE DID WITH "configureTimelineAlbum()",
     * TO AVOID ADDING BUGGY AND UNMAINTAINABLE SPIDERWEB CODE!
     *
     * @param string $targetFeed       Target feed for this media ("timeline", "story",
     *                                 but NOT "album", they are handled elsewhere).
     * @param array  $internalMetadata Internal library-generated metadata key-value pairs.
     * @param array  $externalMetadata (optional) User-provided metadata key-value pairs.
     *
     * @throws \InvalidArgumentException
     * @throws \InstagramAPI\Exception\InstagramException
     *
     * @return \InstagramAPI\Response\ConfigureResponse
     */
    public function configureSingleVideo(
        $targetFeed,
        array $internalMetadata,
        array $externalMetadata = [])
    {
        // Determine the target endpoint for the video.
        switch ($targetFeed) {
        case 'timeline':
            $endpoint = 'media/configure/';
            break;
        case 'story':
            $endpoint = 'media/configure_to_story/';
            break;
        default:
            throw new \InvalidArgumentException(sprintf('Bad target feed "%s".', $targetFeed));
        }

        // Available external metadata parameters:
        /** @var string|null Caption to use for the media. */
        $captionText = isset($externalMetadata['caption']) ? $externalMetadata['caption'] : null;
        /** @var string[]|null Array of numerical UserPK IDs of people tagged in
         * your video. ONLY USED IN STORY VIDEOS! TODO: Actually, it's not even
         * implemented for stories. */
        $usertags = (isset($externalMetadata['usertags']) && $targetFeed == 'story') ? $externalMetadata['usertags'] : null;
        /** @var Response\Model\Location|null A Location object describing where
         the media was taken. NOT USED FOR STORY MEDIA! */
        $location = (isset($externalMetadata['location']) && $targetFeed != 'story') ? $externalMetadata['location'] : null;

        // Fix very bad external user-metadata values.
        if (!is_string($captionText)) {
            $captionText = '';
        }

        // Critically important internal library-generated metadata parameters:
        /** @var string The ID of the entry to configure. */
        $uploadId = $internalMetadata['uploadId'];
        /** @var array Video details array. */
        $videoDetails = $internalMetadata['videoDetails'];

        // Build the request...
        $request = $this->ig->request($endpoint)
            ->addParam('video', 1)
            ->addPost('video_result', 'deprecated')
            ->addPost('upload_id', $uploadId)
            ->addPost('poster_frame_index', 0)
            ->addPost('length', round($videoDetails['duration'], 1))
            ->addPost('audio_muted', false)
            ->addPost('filter_type', 0)
            ->addPost('source_type', 4)
            ->addPost('video_result', 'deprecated')
            ->addPost('device',
                [
                    'manufacturer'      => $this->ig->device->getManufacturer(),
                    'model'             => $this->ig->device->getModel(),
                    'android_version'   => $this->ig->device->getAndroidVersion(),
                    'android_release'   => $this->ig->device->getAndroidRelease(),
                ])
            ->addPost('extra',
                [
                    'source_width'  => $videoDetails['width'],
                    'source_height' => $videoDetails['height'],
                ])
            ->addPost('_csrftoken', $this->ig->client->getToken())
            ->addPost('_uuid', $this->ig->uuid)
            ->addPost('_uid', $this->ig->account_id);

        if ($targetFeed == 'story') {
            $request
                ->addPost('configure_mode', 1) // 1 - REEL_SHARE, 2 - DIRECT_STORY_SHARE
                ->addPost('story_media_creation_date', time() - mt_rand(10, 20))
                ->addPost('client_shared_at', time() - mt_rand(3, 10))
                ->addPost('client_timestamp', time());
        }

        $request->addPost('caption', $captionText);

        if ($targetFeed == 'story') {
            $request->addPost('story_media_creation_date', time());
            if (!is_null($usertags)) {
                // Reel Mention example:
                // [{\"y\":0.3407772676161919,\"rotation\":0,\"user_id\":\"USER_ID\",\"x\":0.39892578125,\"width\":0.5619921875,\"height\":0.06011525487256372}]
                // NOTE: The backslashes are just double JSON encoding, ignore
                // that and just give us an array with these clean values, don't
                // try to encode it in any way, we do all encoding to match the above.
                // This post field will get wrapped in another json_encode call during transfer.
                $request->addPost('reel_mentions', json_encode($usertags));
            }
        }

        if ($location instanceof Response\Model\Location) {
            $loc = [
                $location->getExternalIdSource().'_id'   => $location->getExternalId(),
                'name'                                   => $location->getName(),
                'lat'                                    => $location->getLat(),
                'lng'                                    => $location->getLng(),
                'address'                                => $location->getAddress(),
                'external_source'                        => $location->getExternalIdSource(),
            ];

            $request
                ->addPost('location', json_encode($loc))
                ->addPost('geotag_enabled', '1')
                ->addPost('posting_latitude', $location->getLat())
                ->addPost('posting_longitude', $location->getLng())
                ->addPost('media_latitude', $location->getLat())
                ->addPost('media_longitude', $location->getLng())
                ->addPost('av_latitude', 0.0)
                ->addPost('av_longitude', 0.0);
        }

        $configure = $request->getResponse(new Response\ConfigureResponse());

        return $configure;
    }

    /**
     * Helper function for reliably configuring videos.
     *
     * Exactly the same as configureSingleVideo() but performs multiple attempts. Very
     * useful since Instagram sometimes can't configure a newly uploaded video
     * file until a few seconds have passed.
     *
     * @param string $targetFeed       Target feed for this media ("timeline", "story",
     *                                 but NOT "album", they are handled elsewhere).
     * @param array  $internalMetadata Internal library-generated metadata key-value pairs.
     * @param array  $externalMetadata (optional) User-provided metadata key-value pairs.
     * @param int    $maxAttempts      Total attempts to configure video before throwing.
     *
     * @throws \InvalidArgumentException
     * @throws \InstagramAPI\Exception\InstagramException
     *
     * @return \InstagramAPI\Response\ConfigureResponse
     *
     * @see Internal::configureSingleVideo() for available metadata fields.
     */
    public function configureSingleVideoWithRetries(
        $targetFeed,
        array $internalMetadata,
        array $externalMetadata = [],
        $maxAttempts = 5)
    {
        // We require at least 1 attempt, otherwise we can't do anything.
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('The maxAttempts parameter must be 1 or higher.');
        }

        for ($attempt = 1; $attempt <= $maxAttempts; ++$attempt) {
            try {
                // Attempt to configure video parameters.
                $configure = $this->configureSingleVideo($targetFeed, $internalMetadata, $externalMetadata);
                break; // Success. Exit loop.
            } catch (\InstagramAPI\Exception\InstagramException $e) {
                if ($attempt < $maxAttempts && strpos($e->getMessage(), 'Transcode timeout') !== false) {
                    // Do nothing, since we'll be retrying the failed configure...
                    sleep(1); // Just wait a little before the next retry.
                } else {
                    // Re-throw all unhandled exceptions.
                    throw $e;
                }
            }
        }

        return $configure; // ConfigureResponse
    }

    /**
     * Configures parameters for a whole album of uploaded media files.
     *
     * WARNING TO CONTRIBUTORS: THIS IS ONLY FOR *TIMELINE ALBUMS*. DO NOT MAKE
     * IT DO ANYTHING ELSE, TO AVOID ADDING BUGGY AND UNMAINTAINABLE SPIDERWEB
     * CODE!
     *
     * @param array $media            Extended media array coming from Timeline::uploadAlbum(),
     *                                containing the user's per-file metadata,
     *                                and internally generated per-file metadata.
     * @param array $internalMetadata Internal library-generated metadata key-value pairs.
     * @param array $externalMetadata (optional) User-provided metadata key-value pairs
     *                                for the album itself (its caption, location, etc).
     *
     * @throws \InvalidArgumentException
     * @throws \InstagramAPI\Exception\InstagramException
     *
     * @return \InstagramAPI\Response\ConfigureResponse
     */
    public function configureTimelineAlbum(
        array $media,
        array $internalMetadata,
        array $externalMetadata = [])
    {
        $endpoint = 'media/configure_sidecar/';

        // Available external metadata parameters:
        /** @var string|null Caption to use for the album. */
        $captionText = isset($externalMetadata['caption']) ? $externalMetadata['caption'] : null;
        /** @var Response\Model\Location|null A Location object describing where
         the album was taken. */
        $location = isset($externalMetadata['location']) ? $externalMetadata['location'] : null;

        // Fix very bad external user-metadata values.
        if (!is_string($captionText)) {
            $captionText = '';
        }

        // Critically important internal library-generated metadata parameters:
        // NOTE: NO INTERNAL DATA IS NEEDED HERE YET.

        // Build the album's per-children metadata.
        $date = date('Y:m:d H:i:s');
        $childrenMetadata = [];
        foreach ($media as $item) {
            // Get all of the common, INTERNAL per-file metadata.
            $uploadId = $item['internalMetadata']['uploadId'];

            switch ($item['type']) {
            case 'photo':
                // Get all of the INTERNAL per-PHOTO metadata.
                /** @var int|float */
                $photoWidth = $item['internalMetadata']['photoWidth'];
                /** @var int|float */
                $photoHeight = $item['internalMetadata']['photoHeight'];

                // Build this item's configuration.
                $photoConfig = [
                    'date_time_original'  => $date,
                    'scene_type'          => 1,
                    'disable_comments'    => false,
                    'upload_id'           => $uploadId,
                    'source_type'         => 0,
                    'scene_capture_type'  => 'standard',
                    'date_time_digitized' => $date,
                    'geotag_enabled'      => false,
                    'camera_position'     => 'back',
                    'edits'               => [
                        'filter_strength' => 1,
                        'filter_name'     => 'IGNormalFilter',
                    ],
                ];

                // This usertag per-file EXTERNAL metadata is only supported for PHOTOS!
                if (isset($item['usertags'])) {
                    $photoConfig['usertags'] = json_encode(['in' => $item['usertags']]);
                }

                $childrenMetadata[] = $photoConfig;
                break;
            case 'video':
                // Get all of the INTERNAL per-VIDEO metadata.
                /** @var array Video details array. */
                $videoDetails = $item['internalMetadata']['videoDetails'];

                // Build this item's configuration.
                $videoConfig = [
                    'length'              => round($videoDetails['duration'], 1),
                    'date_time_original'  => $date,
                    'scene_type'          => 1,
                    'poster_frame_index'  => 0,
                    'trim_type'           => 0,
                    'disable_comments'    => false,
                    'upload_id'           => $uploadId,
                    'source_type'         => 'library',
                    'geotag_enabled'      => false,
                    'edits'               => [
                        'length'          => round($videoDetails['duration'], 1),
                        'cinema'          => 'unsupported',
                        'original_length' => round($videoDetails['duration'], 1),
                        'source_type'     => 'library',
                        'start_time'      => 0,
                        'camera_position' => 'unknown',
                        'trim_type'       => 0,
                    ],
                ];

                $childrenMetadata[] = $videoConfig;
                break;
            }
        }

        // Build the request...
        $request = $this->ig->request($endpoint)
            ->addPost('_csrftoken', $this->ig->client->getToken())
            ->addPost('_uid', $this->ig->account_id)
            ->addPost('_uuid', $this->ig->uuid)
            ->addPost('client_sidecar_id', Utils::generateUploadId())
            ->addPost('caption', $captionText)
            ->addPost('children_metadata', $childrenMetadata);

        if ($location instanceof Response\Model\Location) {
            $loc = [
                $location->getExternalIdSource().'_id'   => $location->getExternalId(),
                'name'                                   => $location->getName(),
                'lat'                                    => $location->getLat(),
                'lng'                                    => $location->getLng(),
                'address'                                => $location->getAddress(),
                'external_source'                        => $location->getExternalIdSource(),
            ];

            $request
                ->addPost('location', json_encode($loc))
                ->addPost('geotag_enabled', '1')
                ->addPost('posting_latitude', $location->getLat())
                ->addPost('posting_longitude', $location->getLng())
                ->addPost('media_latitude', $location->getLat())
                ->addPost('media_longitude', $location->getLng())
                ->addPost('exif_latitude', 0.0)
                ->addPost('exif_longitude', 0.0);
        }

        $configure = $request->getResponse(new Response\ConfigureResponse());

        return $configure;
    }

    /**
     * Helper function for reliably configuring albums.
     *
     * Exactly the same as configureTimelineAlbum() but performs multiple
     * attempts. Very useful since Instagram sometimes can't configure a newly
     * uploaded video file until a few seconds have passed.
     *
     * @param array $media            Extended media array coming from Timeline::uploadAlbum(),
     *                                containing the user's per-file metadata,
     *                                and internally generated per-file metadata.
     * @param array $internalMetadata Internal library-generated metadata key-value pairs.
     * @param array $externalMetadata (optional) User-provided metadata key-value pairs
     *                                for the album itself (its caption, location, etc).
     * @param int   $maxAttempts      Total attempts to configure videos before throwing.
     *
     * @throws \InvalidArgumentException
     * @throws \InstagramAPI\Exception\InstagramException
     *
     * @return \InstagramAPI\Response\ConfigureResponse
     *
     * @see Internal::configureTimelineAlbum() for available metadata fields.
     */
    public function configureTimelineAlbumWithRetries(
        array $media,
        array $internalMetadata,
        array $externalMetadata = [],
        $maxAttempts = 5)
    {
        // We require at least 1 attempt, otherwise we can't do anything.
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('The maxAttempts parameter must be 1 or higher.');
        }

        for ($attempt = 1; $attempt <= $maxAttempts; ++$attempt) {
            try {
                // Attempt to configure album parameters.
                $configure = $this->configureTimelineAlbum($media, $internalMetadata, $externalMetadata);
                break; // Success. Exit loop.
            } catch (\InstagramAPI\Exception\InstagramException $e) {
                if ($attempt < $maxAttempts && strpos($e->getMessage(), 'Transcode timeout') !== false) {
                    // Do nothing, since we'll be retrying the failed configure...
                    sleep(1); // Just wait a little before the next retry.
                } else {
                    // Re-throw all unhandled exceptions.
                    throw $e;
                }
            }
        }

        return $configure; // ConfigureResponse
    }

    /**
     * Saves active experiments.
     *
     * @param Response\SyncResponse $syncResponse
     *
     * @throws \InstagramAPI\Exception\SettingsException
     */
    protected function _saveExperiments(
        Response\SyncResponse $syncResponse)
    {
        $experiments = [];
        foreach ($syncResponse->experiments as $experiment) {
            if (!isset($experiment->name)) {
                continue;
            }

            $group = $experiment->name;
            if (!isset($experiments[$group])) {
                $experiments[$group] = [];
            }

            if (!isset($experiment->params)) {
                continue;
            }

            foreach ($experiment->params as $param) {
                if (!isset($param->name)) {
                    continue;
                }

                $experiments[$group][$param->name] = $param->value;
            }
        }

        // Save the experiments and the last time we refreshed them.
        $this->ig->experiments = $this->ig->settings->setExperiments($experiments);
        $this->ig->settings->set('last_experiments', time());
    }

    /**
     * Perform an Instagram "feature synchronization" call.
     *
     * @param bool $prelogin
     *
     * @throws \InstagramAPI\Exception\InstagramException
     *
     * @return \InstagramAPI\Response\SyncResponse
     */
    public function syncFeatures(
        $prelogin = false)
    {
        if ($prelogin) {
            return $this->ig->request('qe/sync/')
                ->setNeedsAuth(false)
                ->addPost('id', $this->ig->uuid)
                ->addPost('experiments', Constants::LOGIN_EXPERIMENTS)
                ->getResponse(new Response\SyncResponse());
        } else {
            $result = $this->ig->request('qe/sync/')
                ->addPost('_uuid', $this->ig->uuid)
                ->addPost('_uid', $this->ig->account_id)
                ->addPost('_csrftoken', $this->ig->client->getToken())
                ->addPost('id', $this->ig->account_id)
                ->addPost('experiments', Constants::EXPERIMENTS)
                ->getResponse(new Response\SyncResponse());

            // Save the updated experiments for this user.
            $this->_saveExperiments($result);

            return $result;
        }
    }

    /**
     * Registers advertising identifier.
     *
     * @throws \InstagramAPI\Exception\InstagramException
     *
     * @return \InstagramAPI\Response
     */
    public function logAttribution()
    {
        return $this->ig->request('attribution/log_attribution/')
            ->setNeedsAuth(false)
            ->addPost('adid', $this->ig->advertising_id)
            ->getResponse(new Response());
    }

    /**
     * Get megaphone log.
     *
     * @throws \InstagramAPI\Exception\InstagramException
     *
     * @return \InstagramAPI\Response\MegaphoneLogResponse
     */
    public function getMegaphoneLog()
    {
        return $this->ig->request('megaphone/log/')
            ->setSignedPost(false)
            ->addPost('type', 'feed_aysf')
            ->addPost('action', 'seen')
            ->addPost('reason', '')
            ->addPost('_uuid', $this->ig->uuid)
            ->addPost('device_id', $this->ig->device_id)
            ->addPost('_csrftoken', $this->ig->client->getToken())
            ->addPost('uuid', md5(time()))
            ->getResponse(new Response\MegaphoneLogResponse());
    }

    /**
     * Get Facebook OTA (Over-The-Air) update information.
     *
     * @throws \InstagramAPI\Exception\InstagramException
     *
     * @return \InstagramAPI\Response\FacebookOTAResponse
     */
    public function getFacebookOTA()
    {
        return $this->ig->request('facebook_ota/')
            ->addParam('fields', Constants::FACEBOOK_OTA_FIELDS)
            ->addParam('custom_user_id', $this->ig->account_id)
            ->addParam('signed_body', Signatures::generateSignature('').'.')
            ->addParam('ig_sig_key_version', Constants::SIG_KEY_VERSION)
            ->addParam('version_code', Constants::VERSION_CODE)
            ->addParam('version_name', Constants::IG_VERSION)
            ->addParam('custom_app_id', Constants::FACEBOOK_ORCA_APPLICATION_ID)
            ->addParam('custom_device_id', $this->ig->uuid)
            ->getResponse(new Response\FacebookOTAResponse());
    }
}
