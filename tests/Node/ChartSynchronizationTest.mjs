import assert from 'node:assert/strict';
import test from 'node:test';
import { createChartSynchronization } from '../../resources/js/utils/chartSynchronization.js';

const deferred = () => {
    let resolve;
    const promise = new Promise((complete) => {
        resolve = complete;
    });

    return { promise, resolve };
};

test('chart synchronization serializes work and coalesces rapid requests to the latest state', async () => {
    const firstRun = deferred();
    const startedWith = [];
    let currentState = 'initial';

    const synchronization = createChartSynchronization(async () => {
        startedWith.push(currentState);

        if (startedWith.length === 1) {
            await firstRun.promise;
        }
    });

    const initialRequest = synchronization.request();
    currentState = 'intermediate';
    const intermediateRequest = synchronization.request();
    currentState = 'latest';
    const latestRequest = synchronization.request();
    firstRun.resolve();

    await Promise.all([initialRequest, intermediateRequest, latestRequest]);

    assert.deepEqual(startedWith, ['initial', 'latest']);
});

test('disposing chart synchronization drops queued work after the active task', async () => {
    const activeRun = deferred();
    let runCount = 0;
    const synchronization = createChartSynchronization(async () => {
        runCount += 1;
        await activeRun.promise;
    });

    const activeRequest = synchronization.request();
    synchronization.request();
    synchronization.dispose();
    activeRun.resolve();

    await activeRequest;

    assert.equal(runCount, 1);
    await synchronization.request();
    assert.equal(runCount, 1);
});

test('chart synchronization requires a callback', () => {
    assert.throws(() => createChartSynchronization(), TypeError);
});
