# NEXO Equipo Local

Cliente de Windows para registrar visitas sin Internet.

## Ejecutar

```powershell
npm install
npm start
```

Los datos se guardan en:

```text
Documentos\NEXO-Equipo\
```

Cada visita crea una carpeta con:

- `visita.json`: datos de la visita.
- `estado.json`: estado local (`pendiente` por ahora).
- Fotos y documentos adjuntos.

## Siguiente etapa

Agregar el inicio de sesion contra el servidor y el endpoint de sincronizacion para enviar unicamente las carpetas pendientes. La URL del servidor se configura desde la aplicacion.
