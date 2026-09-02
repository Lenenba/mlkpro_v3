export const CLIENT_BOOKING_STEP_KEYS = Object.freeze([
    'preferences',
    'slot',
    'review',
    'done',
]);

const isValidStepIndex = (stepIndex) => Number.isInteger(stepIndex)
    && stepIndex >= 0
    && stepIndex < CLIENT_BOOKING_STEP_KEYS.length;

export const createClientBookingStepState = () => ({
    currentStep: 0,
    maxVisitedStep: 0,
    completed: false,
});

export const canVisitClientBookingStep = (state, targetStep) => {
    if (!isValidStepIndex(targetStep)) {
        return false;
    }

    if (state.completed) {
        return targetStep === CLIENT_BOOKING_STEP_KEYS.length - 1;
    }

    return targetStep <= state.maxVisitedStep;
};

export const transitionClientBookingStep = (state, targetStep, options = {}) => {
    if (!isValidStepIndex(targetStep)) {
        return state;
    }

    if (!options.force && !canVisitClientBookingStep(state, targetStep)) {
        return state;
    }

    return {
        currentStep: targetStep,
        maxVisitedStep: Math.max(state.maxVisitedStep, targetStep),
        completed: targetStep === CLIENT_BOOKING_STEP_KEYS.length - 1,
    };
};

export const invalidateClientBookingStepsFrom = (state, firstInvalidStep) => {
    if (!isValidStepIndex(firstInvalidStep)) {
        return state;
    }

    const lastValidStep = Math.max(0, firstInvalidStep - 1);

    return {
        currentStep: Math.min(state.currentStep, lastValidStep),
        maxVisitedStep: Math.min(state.maxVisitedStep, lastValidStep),
        completed: false,
    };
};
