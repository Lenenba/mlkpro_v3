export function durationSegments(durationMs, seconds, maxClips = 30) {
    const step = Math.round(Number(seconds) * 1000);
    if (!Number.isFinite(durationMs) || durationMs <= 0 || !Number.isFinite(step) || step < 1000
        || step > 300000 || Math.ceil(durationMs / step) > maxClips) {
        return [];
    }
    const segments = [];
    for (let start = 0; start < durationMs; start += step) {
        segments.push({ start_ms: start, end_ms: Math.min(durationMs, start + step) });
    }
    return segments;
}

export function validVideoSegments(segments, durationMs, maxClips = 30) {
    if (!Array.isArray(segments) || !segments.length || segments.length > maxClips) return false;
    let previousEnd = 0;
    return segments.every(({ start_ms: start, end_ms: end }) => {
        const valid = Number.isInteger(start) && Number.isInteger(end)
            && start >= previousEnd && end > start && end <= durationMs && end - start <= 300000;
        previousEnd = end;
        return valid;
    });
}

export function videoTime(milliseconds) {
    const seconds = Math.max(0, Math.floor((Number(milliseconds) || 0) / 1000));
    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}

export function videoIsBusy(project) {
    return ['pending', 'processing'].includes(project?.status)
        || ['pending', 'processing'].includes(project?.intelligence_status)
        || (project?.clips || []).some((clip) => ['pending', 'processing'].includes(clip.status));
}

export function validCaptionCues(cues, duration) {
    let end = 0;
    return Array.isArray(cues) && cues.length <= 1000 && cues.every((cue) => {
        const valid = Number.isInteger(cue.start_ms) && Number.isInteger(cue.end_ms)
            && cue.start_ms >= end && cue.end_ms > cue.start_ms && cue.end_ms <= duration
            && typeof cue.text === 'string' && cue.text.trim().length > 0
            && [...cue.text].length <= 160 && !/[\x00-\x08\x0B\x0C\x0E-\x1F]/u.test(cue.text);
        end = cue.end_ms;
        return valid;
    });
}

export function parseVideoSrt(text, duration) {
    const timestamp = (value) => {
        const match = /^(\d{2,}):([0-5]\d):([0-5]\d)[,.](\d{3})$/.exec(value);
        if (!match) throw new Error('invalid_srt');
        return ((Number(match[1]) * 3600 + Number(match[2]) * 60 + Number(match[3])) * 1000) + Number(match[4]);
    };
    const blocks = text.replace(/^\uFEFF/, '').replace(/\r\n?/g, '\n').trim().split(/\n[ \t]*\n/);
    if (blocks.length > 1000) throw new Error('invalid_srt');
    const cues = blocks.map((block) => {
        const lines = block.split('\n');
        if (/^\d+$/.test(lines[0].trim())) lines.shift();
        const times = /^\s*(\S+)\s+-->\s+(\S+)\s*$/.exec(lines.shift() || '');
        if (!times) throw new Error('invalid_srt');
        return { start_ms: timestamp(times[1]), end_ms: timestamp(times[2]), text: lines.join('\n').replace(/<[^>]*>/g, '').trim() };
    });
    if (!cues.length || !validCaptionCues(cues, duration)) throw new Error('invalid_srt');
    return cues;
}

export function clipVideoCaptions(cues, start, end) {
    return cues.filter((cue) => cue.end_ms > start && cue.start_ms < end).map((cue) => ({
        ...cue, start_ms: Math.max(0, cue.start_ms - start), end_ms: Math.min(end, cue.end_ms) - start,
    }));
}

export function videoSrt(cues) {
    const timestamp = (milliseconds) => {
        const seconds = Math.floor(milliseconds / 1000);
        return `${String(Math.floor(seconds / 3600)).padStart(2, '0')}:${String(Math.floor(seconds / 60) % 60).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')},${String(milliseconds % 1000).padStart(3, '0')}`;
    };
    return cues.map((cue, index) => `${index + 1}\n${timestamp(cue.start_ms)} --> ${timestamp(cue.end_ms)}\n${cue.text.replaceAll('<', '‹').replaceAll('>', '›')}`).join('\n\n') + '\n';
}

export function captionSegments(cues, targetSeconds, duration, maxClips = 30) {
    if (!validCaptionCues(cues, duration) || !cues.length || !Number.isFinite(Number(targetSeconds)) || targetSeconds < 1 || targetSeconds > 300) return [];
    const segments = [];
    let start = cues[0].start_ms;
    let end = start;
    for (const cue of cues) {
        if (end > start && cue.end_ms - start > targetSeconds * 1000) {
            segments.push({ start_ms: start, end_ms: end });
            start = cue.start_ms;
        }
        end = cue.end_ms;
    }
    segments.push({ start_ms: start, end_ms: end });
    return validVideoSegments(segments, duration, maxClips) ? segments : [];
}

export function videoCropPosition(points, time, fallback = { x: 50, y: 50 }) {
    if (!points.length) return fallback;
    if (time <= points[0].time_ms) return { x: points[0].x, y: points[0].y };
    for (let index = 1; index < points.length; index++) {
        if (time <= points[index].time_ms) {
            const a = points[index - 1];
            const b = points[index];
            const fraction = (time - a.time_ms) / (b.time_ms - a.time_ms);
            return { x: a.x + (b.x - a.x) * fraction, y: a.y + (b.y - a.y) * fraction };
        }
    }
    return { x: points.at(-1).x, y: points.at(-1).y };
}
