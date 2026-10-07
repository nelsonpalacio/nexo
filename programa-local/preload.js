const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('nexoLocal', {
    guardarVisita: visita => ipcRenderer.invoke('visitas:guardar', visita),
    listarVisitas: () => ipcRenderer.invoke('visitas:listar'),
    leerEquipo: () => ipcRenderer.invoke('equipo:leer'),
    guardarEquipo: equipo => ipcRenderer.invoke('equipo:guardar', equipo)
});
