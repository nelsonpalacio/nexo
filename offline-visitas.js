(function () {
    'use strict';

    var DB_NAME = 'nexo-offline';
    var STORE_NAME = 'visitas-pendientes';
    var DB_VERSION = 1;

    function abrirBaseDatos() {
        return new Promise(function (resolve, reject) {
            var solicitud = indexedDB.open(DB_NAME, DB_VERSION);
            solicitud.onupgradeneeded = function () {
                var base = solicitud.result;
                if (!base.objectStoreNames.contains(STORE_NAME)) {
                    base.createObjectStore(STORE_NAME, { keyPath: 'id' });
                }
            };
            solicitud.onsuccess = function () { resolve(solicitud.result); };
            solicitud.onerror = function () { reject(solicitud.error); };
        });
    }

    function ejecutar(transaccion, modo) {
        return abrirBaseDatos().then(function (base) {
            return new Promise(function (resolve, reject) {
                var almacen = base.transaction(STORE_NAME, modo).objectStore(STORE_NAME);
                var solicitud = transaccion(almacen);
                solicitud.onsuccess = function () { resolve(solicitud.result); };
                solicitud.onerror = function () { reject(solicitud.error); };
            });
        });
    }

    function leerPendientes() {
        return ejecutar(function (almacen) { return almacen.getAll(); }, 'readonly');
    }

    function guardarPendiente(pendiente) {
        return ejecutar(function (almacen) { return almacen.put(pendiente); }, 'readwrite');
    }

    function solicitarSincronizacion() {
        if (!navigator.serviceWorker || !navigator.serviceWorker.ready) return;
        navigator.serviceWorker.ready.then(function (registro) {
            if (registro.sync) {
                return registro.sync.register('nexo-sincronizar-visitas');
            }
        }).catch(function () {});
    }

    function eliminarPendiente(id) {
        return ejecutar(function (almacen) { return almacen.delete(id); }, 'readwrite');
    }

    function convertirFormulario(formulario) {
        var datos = [];
        Array.prototype.forEach.call(formulario.elements, function (campo) {
            if (!campo.name || (campo.disabled && campo.tagName !== 'SELECT') || (campo.type === 'file' && !campo.files.length)) return;
            if (campo.type === 'file') {
                Array.prototype.forEach.call(campo.files, function (archivo) {
                    datos.push({ nombre: campo.name, archivo: archivo });
                });
            } else if ((campo.type !== 'checkbox' && campo.type !== 'radio') || campo.checked) {
                datos.push({ nombre: campo.name, valor: campo.value });
            }
        });
        return datos;
    }

    function crearFormulario(datos) {
        var formulario = new FormData();
        datos.forEach(function (dato) {
            if (dato.nombre !== 'csrf_token') {
                formulario.append(dato.nombre, dato.archivo || dato.valor || '');
            }
        });
        return formulario;
    }

    function guardarFormularioPendiente(formulario) {
        var pendiente = {
            id: 'visita-' + Date.now() + '-' + Math.random().toString(16).slice(2),
            url: formulario.action || window.location.href,
            datos: convertirFormulario(formulario),
            creada: new Date().toISOString()
        };

        return guardarPendiente(pendiente).then(function () {
            mostrarEstado('Visita y archivos guardados en este dispositivo. Se sincronizaran al volver la conexion.', 'aviso');
            formulario.reset();
            solicitarSincronizacion();
            return actualizarContador();
        });
    }

    window.nexoEnviarVisita = function (formulario) {
        var datos = new FormData(formulario);
        return fetch(formulario.action || window.location.href, {
            method: 'POST',
            body: datos,
            credentials: 'same-origin'
        }).then(function (respuesta) {
            if (!respuesta.ok) throw new Error('El servidor rechazo la visita.');
            if (respuesta.redirected) {
                window.location.href = respuesta.url;
                return;
            }
            return respuesta.text().then(function (html) {
                document.open();
                document.write(html);
                document.close();
            });
        }).catch(function () {
            return guardarFormularioPendiente(formulario).catch(function () {
                mostrarEstado('No se pudo guardar la visita sin conexion.', 'error');
            });
        });
    };

    function obtenerTokenActual(url) {
        return fetch(url, { credentials: 'same-origin' }).then(function (respuesta) {
            if (!respuesta.ok) return '';
            return respuesta.text();
        }).then(function (html) {
            var documento = new DOMParser().parseFromString(html, 'text/html');
            var campo = documento.querySelector('input[name="csrf_token"]');
            return campo ? campo.value : '';
        }).catch(function () {
            return '';
        });
    }

    function mostrarEstado(mensaje, tipo) {
        var elemento = document.querySelector('[data-offline-estado]');
        if (!elemento) return;
        elemento.textContent = mensaje;
        elemento.className = 'offline-estado offline-estado-' + tipo;
        elemento.hidden = false;
    }

    function actualizarContador() {
        return leerPendientes().then(function (pendientes) {
            var boton = document.querySelector('[data-sincronizar-visitas]');
            if (boton) {
                boton.hidden = pendientes.length === 0;
                boton.textContent = 'Sincronizar visitas (' + pendientes.length + ')';
            }
            return pendientes;
        });
    }

    function sincronizar() {
        if (!navigator.onLine) {
            mostrarEstado('Sin conexión. La visita permanece guardada en este dispositivo.', 'aviso');
            return Promise.resolve();
        }

        mostrarEstado('Sincronizando visitas pendientes...', 'proceso');
        return leerPendientes().then(function (pendientes) {
            return pendientes.reduce(function (promesa, pendiente) {
                return promesa.then(function () {
                    return obtenerTokenActual(pendiente.url).then(function (token) {
                        if (!token) throw new Error('La sesión no está disponible.');
                        var formulario = crearFormulario(pendiente.datos);
                        formulario.append('csrf_token', token);
                        return fetch(pendiente.url, {
                            method: 'POST',
                            body: formulario,
                            credentials: 'same-origin'
                        });
                    }).then(function (respuesta) {
                        if (!respuesta.ok || !respuesta.redirected || respuesta.url.indexOf('/visitas/') === -1) {
                            throw new Error('El servidor rechazo la visita.');
                        }
                        return eliminarPendiente(pendiente.id);
                    });
                });
            }, Promise.resolve());
        }).then(function () {
            mostrarEstado('Visitas sincronizadas correctamente.', 'exito');
            return actualizarContador();
        }).catch(function () {
            mostrarEstado('No fue posible sincronizar todo. Se reintentara al recuperar Internet.', 'error');
            return actualizarContador();
        });
    }

    function iniciar() {
        var formulario = document.querySelector('form[data-visita-formulario]');
        if (!formulario || !window.indexedDB) return;

        var boton = document.querySelector('[data-sincronizar-visitas]');
        if (boton) boton.addEventListener('click', sincronizar);

        formulario.addEventListener('submit', function (evento) {
            if (navigator.onLine) return;
            evento.preventDefault();
            window.nexoEnviarVisita(formulario).catch(function () {
                mostrarEstado('No se pudo guardar la visita sin conexión.', 'error');
            });
        });

        window.addEventListener('online', sincronizar);
        actualizarContador();
        if (navigator.serviceWorker) {
            navigator.serviceWorker.register('../service-worker.js').then(function () {
                return navigator.serviceWorker.ready;
            }).then(function (registro) {
                if (registro.active) {
                    registro.active.postMessage({
                        tipo: 'guardar-pagina-captura',
                        url: window.location.href
                    });
                }
            }).catch(function () {});
        }
    }

    document.addEventListener('DOMContentLoaded', iniciar);
}());
