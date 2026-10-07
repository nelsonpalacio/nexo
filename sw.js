self.addEventListener('install', function (evento) {
    evento.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', function (evento) {
    evento.waitUntil(
        caches.delete('nexo-offline-v2').then(function () {
            return self.clients.claim();
        })
    );
});
