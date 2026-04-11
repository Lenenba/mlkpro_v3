import {
    Bug,
    BrushCleaning,
    CircleDollarSign,
    Construction,
    Droplets,
    Fan,
    Fence,
    Flame,
    Hammer,
    House,
    KeyRound,
    Leaf,
    PaintRoller,
    PlugZap,
    ShowerHead,
    ShieldCheck,
    Shovel,
    Sofa,
    Sparkles,
    Sprout,
    TreePine,
    Truck,
    Warehouse,
    Waves,
    Wrench,
} from 'lucide-vue-next';

export const industryIconMap = {
    'tree-pine': TreePine,
    'brush-cleaning': BrushCleaning,
    construction: Construction,
    'plug-zap': PlugZap,
    fan: Fan,
    wrench: Wrench,
    shovel: Shovel,
    leaf: Leaf,
    'paint-roller': PaintRoller,
    'shower-head': ShowerHead,
    sparkles: Sparkles,
    house: House,
    hammer: Hammer,
    bug: Bug,
    'circle-dollar-sign': CircleDollarSign,
    droplets: Droplets,
    fence: Fence,
    flame: Flame,
    'key-round': KeyRound,
    'shield-check': ShieldCheck,
    sofa: Sofa,
    sprout: Sprout,
    truck: Truck,
    warehouse: Warehouse,
    waves: Waves,
};

export const industryIconOptions = [
    { value: 'tree-pine', label: 'Tree Pine' },
    { value: 'brush-cleaning', label: 'Brush Cleaning' },
    { value: 'construction', label: 'Construction' },
    { value: 'plug-zap', label: 'Plug Zap' },
    { value: 'fan', label: 'Fan' },
    { value: 'wrench', label: 'Wrench' },
    { value: 'shovel', label: 'Shovel' },
    { value: 'leaf', label: 'Leaf' },
    { value: 'paint-roller', label: 'Paint Roller' },
    { value: 'shower-head', label: 'Shower Head' },
    { value: 'sparkles', label: 'Sparkles' },
    { value: 'house', label: 'House' },
    { value: 'hammer', label: 'Hammer' },
    { value: 'bug', label: 'Bug' },
    { value: 'circle-dollar-sign', label: 'Circle Dollar Sign' },
    { value: 'droplets', label: 'Droplets' },
    { value: 'fence', label: 'Fence' },
    { value: 'flame', label: 'Flame' },
    { value: 'key-round', label: 'Key Round' },
    { value: 'shield-check', label: 'Shield Check' },
    { value: 'sofa', label: 'Sofa' },
    { value: 'sprout', label: 'Sprout' },
    { value: 'truck', label: 'Truck' },
    { value: 'warehouse', label: 'Warehouse' },
    { value: 'waves', label: 'Waves' },
];

export const sanitizeIndustryIconKey = (value) => (
    Object.prototype.hasOwnProperty.call(industryIconMap, value) ? value : ''
);

export const inferIndustryIconKey = (label) => {
    const normalized = String(label || '').toLowerCase();

    if (normalized.includes('arbor') || normalized.includes('tree')) return 'tree-pine';
    if (normalized.includes('clean')) return 'brush-cleaning';
    if (normalized.includes('electric')) return 'plug-zap';
    if (normalized.includes('hvac')) return 'fan';
    if (normalized.includes('paint')) return 'paint-roller';
    if (normalized.includes('plumb')) return 'shower-head';
    if (normalized.includes('pest') || normalized.includes('extermin')) return 'bug';
    if (normalized.includes('pool') || normalized.includes('spa')) return 'waves';
    if (normalized.includes('drain') || normalized.includes('irrig')) return 'droplets';
    if (normalized.includes('fire') || normalized.includes('heat')) return 'flame';
    if (normalized.includes('security') || normalized.includes('alarm')) return 'shield-check';
    if (normalized.includes('lock') || normalized.includes('key')) return 'key-round';
    if (normalized.includes('roof') || normalized.includes('home')) return 'house';
    if (normalized.includes('moving') || normalized.includes('delivery')) return 'truck';
    if (normalized.includes('storage') || normalized.includes('warehouse')) return 'warehouse';
    if (normalized.includes('landscap')) return 'shovel';
    if (normalized.includes('fence') || normalized.includes('gate')) return 'fence';
    if (normalized.includes('lawn') || normalized.includes('garden')) return 'leaf';
    if (normalized.includes('nursery') || normalized.includes('plant')) return 'sprout';
    if (normalized.includes('handyman')) return 'wrench';
    if (normalized.includes('contractor') || normalized.includes('construction')) return 'construction';
    if (normalized.includes('furniture') || normalized.includes('interior')) return 'sofa';
    if (normalized.includes('finance') || normalized.includes('payment')) return 'circle-dollar-sign';

    return 'hammer';
};

export const resolveIndustryIconKey = (card) => (
    sanitizeIndustryIconKey(card?.icon) || inferIndustryIconKey(card?.label)
);

export const resolveIndustryIconComponent = (card) => (
    industryIconMap[resolveIndustryIconKey(card)] || Hammer
);

export const createIndustryCard = (overrides = {}) => ({
    id: overrides.id || `industry-card-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    label: overrides.label || '',
    href: overrides.href || '',
    icon: sanitizeIndustryIconKey(overrides.icon || ''),
});

export const ensureIndustryCards = (cards) => (
    Array.isArray(cards) ? cards.map((card) => createIndustryCard(card)) : []
);

export const defaultIndustryCards = (locale = 'fr') => {
    if (locale === 'fr') {
        return [
            { id: 'industry-arborists', label: 'Arboristes', href: '', icon: 'tree-pine' },
            { id: 'industry-commercial-cleaning', label: 'Nettoyage commercial', href: '/pages/industry-cleaning', icon: 'brush-cleaning' },
            { id: 'industry-construction', label: 'Construction & entrepreneurs', href: '', icon: 'construction' },
            { id: 'industry-electrical', label: 'Entrepreneur electrique', href: '/pages/industry-electrical', icon: 'plug-zap' },
            { id: 'industry-hvac', label: 'HVAC', href: '/pages/industry-hvac', icon: 'fan' },
            { id: 'industry-handyman', label: 'Homme a tout faire', href: '', icon: 'wrench' },
            { id: 'industry-landscaping', label: 'Amenagement paysager', href: '', icon: 'shovel' },
            { id: 'industry-lawn-care', label: 'Entretien de pelouse', href: '', icon: 'leaf' },
            { id: 'industry-painting', label: 'Peinture', href: '', icon: 'paint-roller' },
            { id: 'industry-plumbing', label: 'Plomberie', href: '/pages/industry-plumbing', icon: 'shower-head' },
            { id: 'industry-residential-cleaning', label: 'Nettoyage residentiel', href: '/pages/industry-cleaning', icon: 'sparkles' },
            { id: 'industry-roofing', label: 'Toiture', href: '', icon: 'house' },
        ];
    }

    if (locale === 'es') {
        return [
            { id: 'industry-arborists', label: 'Arboristas', href: '', icon: 'tree-pine' },
            { id: 'industry-commercial-cleaning', label: 'Limpieza comercial', href: '/pages/industry-cleaning', icon: 'brush-cleaning' },
            { id: 'industry-construction', label: 'Construccion y contratistas', href: '', icon: 'construction' },
            { id: 'industry-electrical', label: 'Contratista electrico', href: '/pages/industry-electrical', icon: 'plug-zap' },
            { id: 'industry-hvac', label: 'HVAC', href: '/pages/industry-hvac', icon: 'fan' },
            { id: 'industry-handyman', label: 'Manitas', href: '', icon: 'wrench' },
            { id: 'industry-landscaping', label: 'Paisajismo', href: '', icon: 'shovel' },
            { id: 'industry-lawn-care', label: 'Cuidado del cesped', href: '', icon: 'leaf' },
            { id: 'industry-painting', label: 'Pintura', href: '', icon: 'paint-roller' },
            { id: 'industry-plumbing', label: 'Fontaneria', href: '/pages/industry-plumbing', icon: 'shower-head' },
            { id: 'industry-residential-cleaning', label: 'Limpieza residencial', href: '/pages/industry-cleaning', icon: 'sparkles' },
            { id: 'industry-roofing', label: 'Techado', href: '', icon: 'house' },
        ];
    }

    return [
        { id: 'industry-arborists', label: 'Arborists', href: '', icon: 'tree-pine' },
        { id: 'industry-commercial-cleaning', label: 'Commercial Cleaning', href: '/pages/industry-cleaning', icon: 'brush-cleaning' },
        { id: 'industry-construction', label: 'Construction & Contractors', href: '', icon: 'construction' },
        { id: 'industry-electrical', label: 'Electrical Contractor', href: '/pages/industry-electrical', icon: 'plug-zap' },
        { id: 'industry-hvac', label: 'HVAC', href: '/pages/industry-hvac', icon: 'fan' },
        { id: 'industry-handyman', label: 'Handyman', href: '', icon: 'wrench' },
        { id: 'industry-landscaping', label: 'Landscaping', href: '', icon: 'shovel' },
        { id: 'industry-lawn-care', label: 'Lawn Care', href: '', icon: 'leaf' },
        { id: 'industry-painting', label: 'Painting', href: '', icon: 'paint-roller' },
        { id: 'industry-plumbing', label: 'Plumbing', href: '/pages/industry-plumbing', icon: 'shower-head' },
        { id: 'industry-residential-cleaning', label: 'Residential Cleaning', href: '/pages/industry-cleaning', icon: 'sparkles' },
        { id: 'industry-roofing', label: 'Roofing', href: '', icon: 'house' },
    ];
};
