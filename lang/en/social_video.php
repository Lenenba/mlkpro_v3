<?php

return [
    'invalid_upload' => 'This chunk does not match the current upload. Upload the video again.',
    'invalid_segments' => 'Choose ordered, non-overlapping clips within the video, up to 5 minutes each.',
    'too_many_clips' => 'Choose between 1 and 30 clips. Increase the segment duration if needed.',
    'busy' => 'Wait for video processing to finish before changing this project.',
    'project_limit' => 'The limit of 50 videos has been reached. Delete an old project before uploading another.',
    'invalid_captions' => 'Captions need text and ordered times without overlap within the video duration.',
    'invalid_crop_points' => 'Crop points must have distinct, ordered times within the video.',
    'captions_unavailable' => 'Caption rendering is unavailable on this server.',
    'already_planned' => 'This series already has publications. Edit them in the calendar.',
    'invalid_connections' => 'Choose connected accounts from this workspace.',
    'future_schedule' => 'Choose a future date and time for the whole series.',
    'invalid_publications' => 'Check the text for each clip and account (280 characters on X, 4,000 elsewhere).',
    'clips_not_ready' => 'All clips must be ready and match the displayed series. Refresh the page.',
    'scheduling_failed' => 'Scheduling failed. Check accounts and publication permissions.',
    'ai_unavailable' => 'OpenAI is not configured.',
];
