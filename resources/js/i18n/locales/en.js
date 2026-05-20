import { mergeLocaleModules } from './merge';

const modules = import.meta.glob('../modules/en/*.json', {
    eager: true,
    import: 'default',
});

export default mergeLocaleModules(modules);
