import assert from 'node:assert/strict';
import test from 'node:test';
import { durationSegments, validVideoSegments, videoIsBusy, videoTime } from '../../resources/js/utils/socialVideo.js';

test('duration cuts cover the original including its short last segment', () => {
    assert.deepEqual(durationSegments(125000, 60), [
        { start_ms: 0, end_ms: 60000 }, { start_ms: 60000, end_ms: 120000 }, { start_ms: 120000, end_ms: 125000 },
    ]);
    assert.deepEqual(durationSegments(60000, 60), [{ start_ms: 0, end_ms: 60000 }]);
    assert.deepEqual(durationSegments(125000, 1), []);
    assert.deepEqual(durationSegments(125000, 0), []);
    assert.deepEqual(durationSegments(125000, Infinity), []);
    assert.deepEqual(durationSegments(0, 60), []);
});

test('manual validation rejects reversed, overlapping and out of bounds cuts', () => {
    assert.equal(validVideoSegments([{ start_ms: 500, end_ms: 1500 }, { start_ms: 2000, end_ms: 2500 }], 2500), true);
    for (const segments of [[], [{ start_ms: -1, end_ms: 1000 }], [{ start_ms: 1000, end_ms: 1000 }],
        [{ start_ms: 0, end_ms: 2501 }], [{ start_ms: 0, end_ms: 1000 }, { start_ms: 500, end_ms: 2000 }]]) {
        assert.equal(validVideoSegments(segments, 2500), false);
    }
    assert.equal(validVideoSegments([{ start_ms: 0, end_ms: 300001 }], 400000), false);
});

test('progress distinguishes queued work from finished or failed renders', () => {
    assert.equal(videoIsBusy({ status: 'pending' }), true);
    assert.equal(videoIsBusy({ status: 'ready', intelligence_status: 'processing' }), true);
    assert.equal(videoIsBusy({ status: 'ready', clips: [{ status: 'processing' }] }), true);
    assert.equal(videoIsBusy({ status: 'ready', clips: [{ status: 'ready' }, { status: 'failed' }] }), false);
    assert.equal(videoIsBusy(null), false);
    assert.equal(videoTime(125500), '2:05');
});

const { captionSegments, clipVideoCaptions, parseVideoSrt, validCaptionCues, videoCropPosition, videoSrt } = await import('../../resources/js/utils/socialVideo.js');

test('imports SRT with UTF-8, BOM and CRLF, then exports clip-relative captions', () => {
    const cues = parseVideoSrt('\uFEFF1\r\n00:00:00,500 --> 00:00:02,500\r\n<b>Été à Montréal</b>\r\n\r\n2\r\n00:00:03,000 --> 00:00:04,000\r\nBonjour', 5000);
    assert.deepEqual(cues, [{ start_ms: 500, end_ms: 2500, text: 'Été à Montréal' }, { start_ms: 3000, end_ms: 4000, text: 'Bonjour' }]);
    const clipped = clipVideoCaptions(cues, 1000, 3500);
    assert.equal(videoSrt(clipped), '1\n00:00:00,000 --> 00:00:01,500\nÉté à Montréal\n\n2\n00:00:02,000 --> 00:00:02,500\nBonjour\n');
    assert.deepEqual(clipVideoCaptions(cues, 2500, 3000), []);
});

test('rejects malformed, overlapping and excessive subtitles without dropping content silently', () => {
    for (const text of ['wrong', '00:00:61,000 --> 00:00:62,000\nWrong', '00:00:01,000 --> 00:00:00,000\nBackwards',
        '00:00:00,000 --> 00:00:09,000\nToo long', '00:00:00,000 --> 00:00:01,000\n' + 'W'.repeat(161)]) {
        assert.throws(() => parseVideoSrt(text, 5000), /invalid_srt/);
    }
    assert.equal(validCaptionCues([{ start_ms: 0, end_ms: 1000, text: 'A' }, { start_ms: 500, end_ms: 1500, text: 'B' }], 2000), false);
    assert.equal(validCaptionCues([{ start_ms: 0, end_ms: 1000, text: 'A\0B' }], 2000), false);
});

test('caption-based cuts preserve entire cues and refuse a plan beyond the clip limit', () => {
    const cues = [{ start_ms: 500, end_ms: 2000, text: 'A' }, { start_ms: 2500, end_ms: 4000, text: 'B' }, { start_ms: 4500, end_ms: 6000, text: 'C' }];
    assert.deepEqual(captionSegments(cues, 4, 6000), [{ start_ms: 500, end_ms: 4000 }, { start_ms: 4500, end_ms: 6000 }]);
    assert.deepEqual(captionSegments(cues, 4, 6000, 1), []);
    assert.deepEqual(captionSegments(cues, 0, 6000), []);
});

test('moving crop interpolates between points and holds its position outside them', () => {
    const points = [{ time_ms: 1000, x: 0, y: 20 }, { time_ms: 3000, x: 100, y: 80 }];
    assert.deepEqual(videoCropPosition(points, 0), { x: 0, y: 20 });
    assert.deepEqual(videoCropPosition(points, 2000), { x: 50, y: 50 });
    assert.deepEqual(videoCropPosition(points, 4000), { x: 100, y: 80 });
    assert.deepEqual(videoCropPosition([], 0, { x: 25, y: 30 }), { x: 25, y: 30 });
});
