const http = require('node:http');
const { test: base } = require('@playwright/test');

exports.test = base.extend({
  origin: async ({ baseURL, browserName, context }, use) => {
    if (browserName !== 'webkit') {
      await use({ baseURL, goOffline: () => context.setOffline(true) });
      return;
    }

    // The WebKit test driver can block navigation before the service worker sees it.
    // Drop connections to this test-only origin to exercise the worker's real fallback.
    let offline = false;
    const proxy = http.createServer((request, response) => {
      if (offline) {
        request.socket.destroy();
        return;
      }
      const upstream = http.request(new URL(request.url, baseURL), {
        method: request.method, headers: request.headers,
      }, (result) => {
        response.writeHead(result.statusCode, result.headers);
        result.pipe(response);
      });
      upstream.on('error', () => response.destroy());
      request.pipe(upstream);
    });
    await new Promise((resolve, reject) => {
      proxy.once('error', reject);
      proxy.listen(0, '127.0.0.1', resolve);
    });
    try {
      await use({ baseURL: `http://127.0.0.1:${proxy.address().port}`, goOffline: () => { offline = true; } });
    } finally {
      proxy.close();
      proxy.closeAllConnections();
    }
  },
});
