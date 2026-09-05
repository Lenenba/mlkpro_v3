import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import test from 'node:test';

const source = readFileSync(resolve('resources/js/Components/AiAssistant/PublicChatWidget.vue'), 'utf8');

const between = (start, end) => {
    const startIndex = source.indexOf(start);
    const endIndex = source.indexOf(end, startIndex);

    assert.notEqual(startIndex, -1, `Missing source marker: ${start}`);
    assert.notEqual(endIndex, -1, `Missing source marker: ${end}`);

    return source.slice(startIndex, endIndex);
};

test('the public chat resumes the tenant conversation stored for the booking context', () => {
    const restoreConversation = between('const restoreConversation = async () => {', 'const startConversation = async () => {');
    const startConversation = between('const startConversation = async () => {', 'const openWidget = async () => {');

    assert.match(source, /`malikia:ai-assistant:\$\{props\.companySlug\}:\$\{props\.channel\}:\$\{bookingLink\}`/u);
    assert.match(source, /window\.sessionStorage\.getItem\(conversationStorageKey\.value\)/u);
    assert.match(source, /window\.sessionStorage\.setItem\(conversationStorageKey\.value, uuid\)/u);
    assert.match(source, /window\.sessionStorage\.removeItem\(conversationStorageKey\.value\)/u);
    assert.match(source, /props\.endpoints\?\.show/u);
    assert.match(source, /endpoint\.replace\('__conversation__', conversationUuid\.value\)/u);
    assert.match(restoreConversation, /conversationUuid\.value = storedUuid/u);
    assert.match(restoreConversation, /axios\.get\(showEndpoint\.value,\s*\{[\s\S]*?company: props\.companySlug,[\s\S]*?channel: props\.channel/u);
    assert.match(restoreConversation, /messages\.value = \(response\?\.data\?\.messages \|\| \[\]\)\.map\(normalizeMessage\)/u);
    assert.match(restoreConversation, /quickReplies\.value = response\?\.data\?\.quick_replies \|\| \[\]/u);
    assert.match(startConversation, /rememberConversation\(conversationUuid\.value\)/u);
});

test('each public chat message carries its tenant, channel, contact and booking metadata', () => {
    const sendMessage = between('const sendMessage = async (quickReplyMessage = \'\') => {', 'const handleSubmit = () => {');

    assert.match(sendMessage, /axios\.post\(messageEndpoint\.value,\s*\{[\s\S]*?message,[\s\S]*?company: props\.companySlug,[\s\S]*?channel: props\.channel,[\s\S]*?visitor_name: props\.visitorName \|\| null,[\s\S]*?visitor_email: props\.visitorEmail \|\| null,[\s\S]*?visitor_phone: props\.visitorPhone \|\| null,[\s\S]*?metadata: props\.initialMetadata \|\| \{\}/u);
});

test('the public chat renders server quick replies and sends their canonical message', () => {
    assert.match(source, /quickReplies\.value = response\?\.data\?\.quick_replies \|\| \[\]/u);
    assert.match(source, /data-testid="public-ai-chat-quick-replies"/u);
    assert.match(source, /v-for="reply in quickReplies"/u);
    assert.match(source, /\{\{ reply\.label \}\}/u);
    assert.match(source, /@click="sendMessage\(reply\.message\)"/u);
});

test('opening the floating chat only restores a session and defers creation until the first message', () => {
    const openWidget = between('const openWidget = async () => {', 'const closeWidget = () => {');
    const sendMessage = between('const sendMessage = async (quickReplyMessage = \'\') => {', 'const handleSubmit = () => {');
    const mounted = between('onMounted(() => {', '</script>');

    assert.match(openWidget, /isOpen\.value = true/u);
    assert.match(openWidget, /await restoreConversation\(\)/u);
    assert.doesNotMatch(openWidget, /startConversation\(/u);
    assert.match(sendMessage, /if \(!hasConversation\.value\) \{\s*await startConversation\(\);\s*\}/u);
    assert.match(mounted, /if \(!isFloating\.value\) \{\s*startConversation\(\);\s*\}/u);
    assert.match(source, /data-testid="public-ai-chat-open"[\s\S]*?@click="openWidget"/u);
});
