const TRANSPARENT_PIXEL = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
const TEMPLATE_SRC_PATTERN = /^\{[a-zA-Z][a-zA-Z0-9?]*\}$/;

const withContainer = (html, callback) => {
    if (typeof document === 'undefined') {
        return String(html || '');
    }

    const container = document.createElement('div');
    container.innerHTML = String(html || '');
    callback(container);
    return container.innerHTML;
};

export const neutralizeTemplateAssetSources = (html) => withContainer(html, (container) => {
    container.querySelectorAll('[src]').forEach((element) => {
        const source = String(element.getAttribute('src') || '').trim();
        if (!TEMPLATE_SRC_PATTERN.test(source)) {
            return;
        }

        element.setAttribute('data-template-src', source);
        element.setAttribute('src', TRANSPARENT_PIXEL);
    });
});

export const restoreTemplateAssetSources = (html) => withContainer(html, (container) => {
    container.querySelectorAll('[data-template-src]').forEach((element) => {
        const source = String(element.getAttribute('data-template-src') || '').trim();
        if (source === '') {
            element.removeAttribute('data-template-src');
            return;
        }

        element.setAttribute('src', source);
        element.removeAttribute('data-template-src');
    });
});
