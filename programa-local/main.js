const { app, BrowserWindow, ipcMain } = require('electron');
const fs = require('fs/promises');
const path = require('path');
const crypto = require('crypto');

let ventana;

function carpetaBase() {
    return path.join(app.getPath('documents'), 'NEXO-Equipo');
}

function carpetaVisitas() {
    return path.join(carpetaBase(), 'visitas');
}

function limpiarNombre(valor) {
    return String(valor || 'sin-dato')
        .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
        .replace(/[^a-zA-Z0-9._-]+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '')
        .slice(0, 100) || 'visita';
}

async function escribirSeguro(archivo, contenido) {
    const temporal = `${archivo}.${crypto.randomBytes(6).toString('hex')}.tmp`;
    await fs.writeFile(temporal, contenido);
    await fs.rename(temporal, archivo);
}

async function prepararCarpetas() {
    await fs.mkdir(carpetaVisitas(), { recursive: true });
    const configuracion = path.join(carpetaBase(), 'equipo.json');
    try { await fs.access(configuracion); } catch {
        await escribirSeguro(configuracion, JSON.stringify({ equipo_id: '', equipo_nombre: '', servidor: '' }, null, 2));
    }
}

async function guardarVisita(_, visita) {
    await prepararCarpetas();
    const codigo = `VIS-${Date.now()}-${crypto.randomInt(1000, 9999)}`;
    const nombreCarpeta = `${codigo}_${limpiarNombre(visita.institucion)}`;
    const carpeta = path.join(carpetaVisitas(), nombreCarpeta);
    await fs.mkdir(carpeta, { recursive: true });

    const archivos = [];
    for (const archivo of (visita.archivos || [])) {
        const nombre = limpiarNombre(archivo.nombre);
        const destino = path.join(carpeta, nombre);
        await fs.writeFile(destino, Buffer.from(archivo.contenido));
        archivos.push(nombre);
    }

    const datos = { ...visita, codigo_visita: codigo, archivos, creada_localmente: new Date().toISOString() };
    await escribirSeguro(path.join(carpeta, 'visita.json'), JSON.stringify(datos, null, 2));
    await escribirSeguro(path.join(carpeta, 'estado.json'), JSON.stringify({ estado: 'pendiente', actualizado: new Date().toISOString() }, null, 2));
    return { codigo, carpeta: nombreCarpeta };
}

async function listarVisitas() {
    await prepararCarpetas();
    const nombres = await fs.readdir(carpetaVisitas(), { withFileTypes: true });
    const resultado = [];
    for (const entrada of nombres.filter(item => item.isDirectory())) {
        try {
            const datos = JSON.parse(await fs.readFile(path.join(carpetaVisitas(), entrada.name, 'visita.json'), 'utf8'));
            const estado = JSON.parse(await fs.readFile(path.join(carpetaVisitas(), entrada.name, 'estado.json'), 'utf8'));
            resultado.push({ ...datos, carpeta: entrada.name, estado: estado.estado });
        } catch { /* Se omiten carpetas incompletas. */ }
    }
    return resultado.sort((a, b) => b.creada_localmente.localeCompare(a.creada_localmente));
}

function crearVentana() {
    ventana = new BrowserWindow({ width: 1100, height: 760, minWidth: 850, minHeight: 600, webPreferences: { preload: path.join(__dirname, 'preload.js'), contextIsolation: true, nodeIntegration: false } });
    ventana.loadFile(path.join(__dirname, 'renderer', 'index.html'));
}

app.whenReady().then(async () => {
    await prepararCarpetas();
    ipcMain.handle('visitas:guardar', guardarVisita);
    ipcMain.handle('visitas:listar', listarVisitas);
    ipcMain.handle('equipo:leer', async () => {
        try { return JSON.parse(await fs.readFile(path.join(carpetaBase(), 'equipo.json'), 'utf8')); } catch { return {}; }
    });
    ipcMain.handle('equipo:guardar', async (_, configuracion) => {
        await prepararCarpetas();
        await escribirSeguro(path.join(carpetaBase(), 'equipo.json'), JSON.stringify(configuracion, null, 2));
        return configuracion;
    });
    crearVentana();
});

app.on('window-all-closed', () => { if (process.platform !== 'darwin') app.quit(); });
