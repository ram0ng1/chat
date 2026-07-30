const config = require('flarum-webpack-config');

module.exports = config({
  useExtensions: [
    // Optional peers. The chat degrades gracefully when they are absent, but the
    // bundle needs to know their module namespaces to import from them.
    'flarum/tags',
    'flarum/mentions',
    'flarum/emoji',
    'flarum-realtime',
  ],
});
