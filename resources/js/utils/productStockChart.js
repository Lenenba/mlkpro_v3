export const PRODUCT_STOCK_PARTITION_KEYS = Object.freeze([
    'in_stock',
    'low_stock',
    'out_of_stock',
]);

const emptyPartition = () => ({
    keys: [],
    values: [],
    total: null,
    isValid: false,
});

const isStockCount = (value) => Number.isInteger(value) && value >= 0;

export const buildProductStockPartition = (stats) => {
    if (!stats || typeof stats !== 'object' || Array.isArray(stats)) {
        return emptyPartition();
    }

    const total = stats.total;
    const values = PRODUCT_STOCK_PARTITION_KEYS.map((key) => stats[key]);

    if (!isStockCount(total) || values.some((value) => !isStockCount(value))) {
        return emptyPartition();
    }

    if (values.reduce((sum, value) => sum + value, 0) !== total) {
        return emptyPartition();
    }

    return {
        keys: [...PRODUCT_STOCK_PARTITION_KEYS],
        values,
        total,
        isValid: true,
    };
};
