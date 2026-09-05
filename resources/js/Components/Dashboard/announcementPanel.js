export const isMediaOnlyAnnouncement = (announcement) => (
    announcement?.display_style === 'media_only'
    && Boolean(announcement?.media_url)
    && ['image', 'video'].includes(announcement?.media_type)
);

export const selectPanelAnnouncements = (announcements, limit = 4) => {
    const items = Array.isArray(announcements) ? announcements : [];

    // A highest-priority full-bleed announcement owns the placement so the
    // panel can truthfully render only its image or video.
    if (isMediaOnlyAnnouncement(items[0])) {
        return items.slice(0, 1);
    }

    // A lower-priority full-bleed announcement cannot be embedded inside an
    // editorial panel without breaking its full-bleed presentation contract.
    const editorialItems = items.filter((item) => !isMediaOnlyAnnouncement(item));

    return Number.isFinite(limit) && limit > 0
        ? editorialItems.slice(0, limit)
        : editorialItems;
};
