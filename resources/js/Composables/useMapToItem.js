export function useMapToItem() {
    /**
     * Map raw data to a formatted item
     * @param {Object} rawData - The raw data object
     * @returns {Object} - The mapped item
     */
    const mapToItem = (rawData) => {
        return {
            name: `${rawData.country} - ${rawData.type}`,
            description: `${rawData.street1}${rawData.street2 ? `, ${rawData.street2}` : ''}`,
            description2: `${rawData.city} - ${rawData.state} - ${rawData.zip}`,
            logo: rawData.type,
        };
    };

    return {
        mapToItem
    };
}
