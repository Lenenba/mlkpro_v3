<?php

return [
    'ffmpeg' => env('SOCIAL_VIDEO_FFMPEG', 'ffmpeg'),
    'ffprobe' => env('SOCIAL_VIDEO_FFPROBE', 'ffprobe'),
    'max_upload_kb' => 262144,
    'max_duration_ms' => 1800000,
    'max_clips' => 30,
    'max_clip_duration_ms' => 300000,
    'process_timeout' => 840,
    'caption_font' => env('SOCIAL_VIDEO_CAPTION_FONT', base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf')),
];
