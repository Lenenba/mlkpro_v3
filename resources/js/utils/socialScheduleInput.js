const LOCAL_DATE_TIME_PATTERN = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,6})?Z?)?$/u;
const EXPLICIT_OFFSET_PATTERN = /(?:Z|[+-]\d{2}:?\d{2})$/iu;

const pad = (value) => String(value).padStart(2, '0');

const normalizeLocalDateTime = (value) => {
    const match = String(value || '').trim().match(LOCAL_DATE_TIME_PATTERN);
    if (!match) {
        return '';
    }

    const [, year, month, day, hour, minute, second = '00'] = match;
    const candidate = new Date(Date.UTC(
        Number(year),
        Number(month) - 1,
        Number(day),
        Number(hour),
        Number(minute),
        Number(second),
    ));

    if (candidate.getUTCFullYear() !== Number(year)
        || candidate.getUTCMonth() !== Number(month) - 1
        || candidate.getUTCDate() !== Number(day)
        || candidate.getUTCHours() !== Number(hour)
        || candidate.getUTCMinutes() !== Number(minute)
        || candidate.getUTCSeconds() !== Number(second)) {
        return '';
    }

    return `${year}-${month}-${day}T${hour}:${minute}`;
};

const browserLocalDateTime = (date) => [
    date.getFullYear(),
    pad(date.getMonth() + 1),
    pad(date.getDate()),
    pad(date.getHours()),
    pad(date.getMinutes()),
];

const zonedLocalDateTime = (date, timeZone) => {
    const formatter = new Intl.DateTimeFormat('en-CA-u-hc-h23', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    });
    const parts = Object.fromEntries(
        formatter.formatToParts(date)
            .filter((part) => part.type !== 'literal')
            .map((part) => [part.type, part.value]),
    );

    return [parts.year, parts.month, parts.day, parts.hour, parts.minute];
};

const legacyInstantInputValue = (value, timeZone) => {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }

    try {
        const [year, month, day, hour, minute] = timeZone
            ? zonedLocalDateTime(date, timeZone)
            : browserLocalDateTime(date);

        return normalizeLocalDateTime(`${year}-${month}-${day}T${hour}:${minute}`);
    } catch {
        return '';
    }
};

export const socialScheduleInputValue = (record) => {
    if (!record || typeof record !== 'object') {
        return '';
    }

    const canonicalLocalValue = normalizeLocalDateTime(record.scheduled_local_time);
    if (canonicalLocalValue !== '') {
        return canonicalLocalValue;
    }

    const legacyValue = String(record.scheduled_for || '').trim();
    if (legacyValue === '') {
        return '';
    }

    if (!EXPLICIT_OFFSET_PATTERN.test(legacyValue)) {
        return normalizeLocalDateTime(legacyValue);
    }

    return legacyInstantInputValue(
        legacyValue,
        String(record.scheduled_timezone || '').trim(),
    );
};
