const CACHE_NAME = 'nexo-offline-v3';
const APP_ROOT = new URL('./', self.registration.scope).pathname;
const PAGINA_OFFLINE = APP_ROOT + 'visitas/nueva.php';

// 1. Agregamos las páginas e imágenes base para que se precarguen
const ARCHIVOS_BASE = [
    PAGINA_OFFLINE,
    APP_ROOT + 'visitas/visitas.php',
    APP_ROOT + 'css/estilos.css',
    APP_ROOT + 'css/visitas.css',
    APP_ROOT + 'offline-visitas.js',
    APP_ROOT + 'logo.png',
    APP_ROOT + 'logo2.png'
];

const DB_NAME = 'nexo-offline';
const STORE_NAME = 'visitas-pendientes';
const DB_VERSION = 1;

function abrirBaseDatos() {
    return new Promise(function (resolve, reject) {
        const solicitud = indexedDB.open(DB_NAME, DB_VERSION);
        solicitud.onupgradeneeded = function () {
            const base = solicitud.result;
            if (!base.objectStoreNames.contains(STORE_NAME)) {
                base.createObjectStore(STORE_NAME, { keyPath: 'id' });
            }
        };
        solicitud.onsuccess = function () { resolve(solicitud.result); };
        solicitud.onerror = function () { reject(solicitud.error); };
    });
}

function leerPendientes() {
    return abrirBaseDatos().then(function (base) {
        return new Promise(function (resolve, reject) {
            const solicitud = base.transaction(STORE_NAME, 'readonly')
                .objectStore(STORE_NAME).getAll();
            solicitud.onsuccess = function () { resolve(solicitud.result || []); };
            solicitud.onerror = function () { reject(solicitud.error); };
        });
    });
}

function eliminarPendiente(id) {
    return abrirBaseDatos().then(function (base) {
        return new Promise(function (resolve, reject) {
            const solicitud = base.transaction(STORE_NAME, 'readwrite')
                .objectStore(STORE_NAME).delete(id);
            solicitud.onsuccess = resolve;
            solicitud.onerror = function () { reject(solicitud.error); };
        });
    });
}

function obtenerToken(url) {
    return fetch(url, { credentials: 'same-origin' }).then(function (respuesta) {
        if (!respuesta.ok) throw new Error('No se pudo abrir la página de captura.');
        return respuesta.text();
    }).then(function (html) {
        const coincidencia = html.match(/name=["']csrf_token["'][^>]*value=["']([^"']+)/i);
        if (!coincidencia) throw new Error('La sesión no está disponible.');
        return coincidencia[1];
    });
}

function sincronizarVisitas() {
    return leerPendientes().then(function (pendientes) {
        return pendientes.reduce(function (promesa, pendiente) {
            return promesa.then(function () {
                return obtenerToken(pendiente.url).then(function (token) {
                    const formulario = new FormData();
                    pendiente.datos.forEach(function (dato) {
                        formulario.append(dato.nombre, dato.archivo || dato.valor || '');
                    });
                    formulario.set('csrf_token', token);
                    return fetch(pendiente.url, {
                        method: 'POST',
                        body: formulario,
                        credentials: 'same-origin'
                    });
                }).then(function (respuesta) {
                    if (!respuesta.ok || !respuesta.redirected || respuesta.url.indexOf('/visitas/') === -1) {
                        throw new Error('El servidor no aceptó la visita.');
                    }
                    return eliminarPendiente(pendiente.id);
                });
            });
        }, Promise.resolve());
    });
}

self.addEventListener('install', function (evento) {
    evento.waitUntil(caches.open(CACHE_NAME).then(function (cache) {
        return cache.addAll(ARCHIVOS_BASE);
    }));
    self.skipWaiting();
});

self.addEventListener('activate', function (evento) {
    evento.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(
                keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))
            );
        }).then(() => self.clients.claim())
    );
});

self.addEventListener('sync', function (evento) {
    if (evento.tag === 'nexo-sincronizar-visitas') {
        evento.waitUntil(sincronizarVisitas());
    }
});

self.addEventListener('message', function (evento) {
    if (!evento.data || evento.data.tipo !== 'guardar-pagina-captura') return;

    evento.waitUntil(fetch(evento.data.url, { credentials: 'include' }).then(function (respuesta) {
        if (!respuesta.ok) return;
        return caches.open(CACHE_NAME).then(function (cache) {
            return cache.put(evento.data.url, respuesta);
        });
    }).catch(function () {}));
});

self.addEventListener('fetch', function (evento) {
    if (evento.request.method !== 'GET') return;

    // Manejo de navegaciones HTML
    if (evento.request.mode === 'navigate') {
        evento.respondWith(
            fetch(evento.request).then(function (respuesta) {
                if (respuesta.ok) {
                    var copia = respuesta.clone();
                    caches.open(CACHE_NAME).then(function (cache) { cache.put(evento.request, copia); });
                }
                return respuesta;
            }).catch(function () {
                return caches.match(evento.request).then(function (respuesta) {
                    return respuesta || caches.match(PAGINA_OFFLINE);
                });
            })
        );
        return;
    }

    // 2. Manejo de CSS, JS e Imágenes (Sin restricción de ruta dura)
    if (['style', 'script', 'image', 'font'].includes(evento.request.destination)) {
        evento.respondWith(
            caches.match(evento.request).then(function (respuesta) {
                if (respuesta) return respuesta;
                
                return fetch(evento.request).then(function (red) {
                    if (red && red.status === 200) {
                        var copia = red.clone();
                        caches.open(CACHE_NAME).then(function (cache) { cache.put(evento.request, copia); });
                    }
                    return red;
                });
            })
        );
    }
});