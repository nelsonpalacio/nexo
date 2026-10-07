const formulario = document.getElementById('visita-form');
const equipoForm = document.getElementById('equipo-form');
const lista = document.getElementById('lista');
const resumen = document.getElementById('resumen');
const mensaje = document.getElementById('mensaje');
const equipoLabel = document.getElementById('equipo-label');

function mostrarMensaje(texto, error = false) {
    mensaje.textContent = texto;
    mensaje.className = error ? 'error' : 'exito';
}

function llenarEquipo(equipo) {
    equipoForm.equipo_id.value = equipo.equipo_id || '';
    equipoForm.equipo_nombre.value = equipo.equipo_nombre || '';
    equipoForm.servidor.value = equipo.servidor || '';
    equipoLabel.textContent = equipo.equipo_nombre || 'Equipo sin configurar';
}

async function cargarEquipo() {
    llenarEquipo(await window.nexoLocal.leerEquipo());
}

async function cargarVisitas() {
    const visitas = await window.nexoLocal.listarVisitas();
    resumen.textContent = `${visitas.length} visita${visitas.length === 1 ? '' : 's'} guardada${visitas.length === 1 ? '' : 's'} localmente`;
    lista.innerHTML = visitas.length ? visitas.map(visita => `<article class="visita"><div><strong>${visita.institucion}</strong><span>${visita.estado} · ${visita.ciudad} · ${visita.codigo_visita}</span></div><b class="${visita.estado === 'pendiente' ? 'pendiente' : 'sincronizada'}">${visita.estado}</b></article>`).join('') : '<p class="vacio">Todavía no hay visitas locales.</p>';
}

formulario.addEventListener('submit', async evento => {
    evento.preventDefault();
    const datos = Object.fromEntries(new FormData(formulario).entries());
    const archivos = Array.from(formulario.archivos.files).map(archivo => ({ nombre: archivo.name, contenido: archivo.arrayBuffer() }));
    datos.archivos = await Promise.all(archivos.map(async archivo => ({ nombre: archivo.nombre, contenido: new Uint8Array(await archivo.contenido) })));
    try {
        const resultado = await window.nexoLocal.guardarVisita(datos);
        formulario.reset();
        mostrarMensaje(`Visita ${resultado.codigo} guardada correctamente.`);
        cargarVisitas();
    } catch (error) {
        mostrarMensaje('No fue posible guardar la visita.', true);
    }
});

equipoForm.addEventListener('submit', async evento => {
    evento.preventDefault();
    const equipo = Object.fromEntries(new FormData(equipoForm).entries());
    await window.nexoLocal.guardarEquipo(equipo);
    llenarEquipo(equipo);
    mostrarMensaje('Configuración del equipo guardada.');
});

document.getElementById('actualizar').addEventListener('click', cargarVisitas);
cargarEquipo();
cargarVisitas();
