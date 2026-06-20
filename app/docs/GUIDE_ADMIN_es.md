# Guía de administración

Esta guía explica cómo funcionan las principales pantallas de administración, sin entrar en el código.

## Rol del espacio de administración

El espacio de administración sirve para:

- seguir las sesiones realizadas por los candidatos
- consultar los detalles de un candidato
- gestionar las certificaciones
- gestionar los paquetes
- gestionar las preguntas
- analizar el rendimiento de las preguntas
- administrar los usuarios

## Sesiones

La página `Sesiones` permite supervisar los intentos recientes.

Puedes ver:

- el candidato
- el paquete
- el tipo de sesión
- la puntuación
- el estado
- un acceso al detalle

El detalle de una sesión permite después:

- revisar las preguntas planteadas
- ver las respuestas del candidato
- comprobar las respuestas correctas
- abrir rápidamente la ficha de la pregunta
- abrir el análisis de rendimiento de la pregunta

## Candidato

La ficha del candidato centraliza la vista por persona.

Permite consultar:

- la información del candidato
- su historial de sesiones
- sus certificaciones
- ciertos parámetros de administración relacionados con su recorrido

## Certificaciones

La página `Certificaciones` ofrece una vista de seguimiento por candidato y por paquete.

Normalmente muestra:

- el estado de la certificación
- la fecha de última obtención
- la fecha de expiración
- el acceso al detalle de la última sesión

## Usuarios

La página `Usuarios` sirve para gestionar las cuentas de la aplicación.

Permite:

- consultar la lista de cuentas
- modificar cierta información de usuario
- ajustar el rol si es necesario

### Eliminación de un usuario

Eliminar una cuenta es irreversible. Esto es lo que ocurre:

- **Eliminado definitivamente**: la cuenta (email, contraseña, rol), los accesos a programas y los desbloqueos de paquetes concedidos a ese usuario.
- **Conservado pero anonimizado**: todas las sesiones y resultados de exámenes permanecen en la base de datos pero ya no están vinculados a ninguna cuenta (user_id puesto a NULL). El historial sigue visible en el admin mediante el email del contacto.
- **Certificaciones**: siguen consultables en la pestaña Certificaciones mediante el email del contacto, siempre que el contacto no sea eliminado.

## Paquetes

La página `Paquetes` sirve para gestionar las certificaciones disponibles en la herramienta.

Un paquete define:

- su nombre
- su color de visualización
- su duración
- su umbral de aprobación
- sus paliers de selección (ver abajo)
- su validez de certificación
- el tiempo de espera después de un fallo

### Paliers de selección (reglas de extracción)

Los paliers definen qué preguntas se extraen durante un examen. Para cada palier se indica una categoría (necesidad), los niveles objetivo (N1/N2/N3) y el número de preguntas a extraer.

**El total de los paliers es el número de preguntas del examen** — ya no existe un campo separado "Número de preguntas".

Al guardar:

- Si no hay paliers configurados: una ventana emergente bloquea el guardado e indica que el paquete no puede utilizarse.
- Si los paliers solicitan más preguntas de las disponibles en la base: una ventana de advertencia permite corregir o guardar de todas formas.

### Estados de un paquete

| Badge | Significado |
|---|---|
| **Listo** | El paquete está listo; las preguntas en la base cubren los paliers |
| **Incompleto** | Los paliers están configurados pero no hay suficientes preguntas en la base |
| **Sin paliers** | Ningún palier configurado — el paquete no puede utilizarse |

Desde `Modificar paquete`, es posible:

- ajustar los parámetros del paquete
- configurar los paliers de selección
- revisar las preguntas asociadas (visible solo si hay paliers configurados)
- abrir la edición de una pregunta
- abrir la vista de rendimiento de una pregunta

## Preguntas

La página `Preguntas` es el banco global de preguntas.

Permite:

- buscar preguntas
- filtrarlas
- modificarlas
- eliminarlas
- abrir el rendimiento de una pregunta

## Importación de preguntas

La página de importación sirve para insertar o actualizar preguntas en masa.

Existen tres modos:

- `Reinicialización`: crea las nuevas preguntas y reemplaza las preguntas/respuestas ya existentes a partir de su ID. Solo se procesan las líneas presentes en el archivo. Las preguntas ausentes del archivo no se eliminan. Las traducciones existentes se marcan para revisión, y las traducciones de respuestas se eliminan cuando las respuestas se recrean.
- `Actualización`: crea o actualiza únicamente las preguntas/respuestas de referencia a partir de su ID. Este modo no lee las columnas de traducción.
- `Traducciones`: actualiza únicamente los textos traducidos del idioma elegido, para preguntas que ya existen en la fuente. Este modo no modifica la fuente, las respuestas correctas, las categorías ni los niveles.

En modo de reinicialización o actualización, si se modifica una pregunta existente, sus respuestas se eliminan y luego se recrean.

El idioma fuente se define en el programa. En los modos `Reinicialización` y `Actualización`, la importación fuerza este idioma e indica el idioma esperado para el archivo. En modo `Traducciones`, la lista de idiomas excluye el idioma fuente del programa.

### FAQ

#### Si reimporto una pregunta con el mismo ID, ¿qué ocurre con las traducciones?

Si la pregunta se reimporta en el mismo programa con el mismo ID externo, la importación encuentra la misma pregunta interna. Por tanto, la traducción del enunciado sigue vinculada a la pregunta, pero se marca para revisión porque la fuente ha sido modificada.

Las respuestas, en cambio, se eliminan y luego se recrean durante una reinicialización o una actualización. Por tanto, las traducciones de las respuestas se eliminan y deberán reimportarse en modo `Traducciones`.

## Análisis

La página `Análisis` ofrece una vista agregada por pregunta sobre todas las sesiones completadas o expiradas.

### Estadísticas globales

Una barra de resumen en la parte superior muestra los indicadores clave para los filtros activos:

- **Preguntas** — número de preguntas distintas que han recibido al menos una respuesta
- **Respuestas** — número total de respuestas registradas
- **Tasa de acierto** — porcentaje global de respuestas correctas
- **Tasa de error** — porcentaje global de respuestas incorrectas
- **Sin cambios** — preguntas cuyo texto es idéntico al que vieron los candidatos
- **Modificadas** — preguntas cuyo texto se actualizó después de algunas sesiones
- **Eliminadas** — preguntas borradas del banco pero aún presentes en el historial

### Filtros de alcance

| Filtro | Uso |
|--------|-----|
| ID de pregunta | localiza una pregunta concreta por su identificador externo |
| Tipo de sesión | limita a Examen, Entrenamiento o ambos |
| Paquete | aísla un paquete específico |
| Estado de la pregunta | filtra por Sin cambios, Modificada o Eliminada |
| Categoría | filtra por valor `knowledge_required` |
| Fecha inicio / fin | acota un rango temporal sobre la fecha de inicio de sesión |

### Filtros avanzados por métrica

Para cada métrica, un comparador permite afinar la lista:

- `=` valor exacto
- `>=` mayor o igual
- `<=` menor o igual
- `entre` — dos valores que definen un intervalo

Métricas disponibles: tasa de acierto (%), tasa de error (%), número de respuestas.

Ejemplo: mostrar solo las preguntas con una tasa de error >= 60 % y al menos 10 respuestas.

### Tabla de preguntas

La tabla se ordena por la columna elegida (clic en el encabezado) y se pagina a 20 filas por página.

| Columna | Descripción |
|---------|-------------|
| Rango | Clasificación global por tasa de acierto descendente, calculada sobre todas las preguntas |
| ID | Identificador externo de la pregunta |
| Pregunta | Texto truncado a 110 caracteres |
| Categoría | Valor `knowledge_required` |
| Estado | Etiqueta: Sin cambios / Modificada / Eliminada |
| Respuestas | Número de veces que la pregunta fue respondida |
| % OK | Tasa de acierto |
| % KO | Tasa de error |
| Acciones | Abrir el editor de la pregunta · Zoom sobre las sesiones |

### Lógica de instantáneas

Las sesiones registran una instantánea del texto de la pregunta en el momento del intento. Esto significa:

- una pregunta **Modificada** conserva sus estadísticas históricas — las cifras siguen siendo válidas, pero el texto mostrado es el actual
- una pregunta **Eliminada** sigue visible en el análisis mientras tenga al menos una respuesta registrada

### Zoom: detalle de sesiones de una pregunta

El botón de zoom abre la vista de detalle que lista cada sesión que incluyó la pregunta:

- fecha y hora del intento
- candidato (correo)
- paquete y tipo de sesión
- resultado: OK (respuesta correcta) o KO (incorrecta)
- enlace al detalle completo de la sesión

Los filtros de tipo de sesión y rango de fechas de la página principal se propagan automáticamente.

### Casos de uso típicos

- **Preguntas difíciles** — ordenar por `% KO` descendente para detectar preguntas problemáticas y revisar su redacción o respuestas
- **Preguntas poco usadas** — ordenar por `Respuestas` ascendente para identificar preguntas casi nunca extraídas
- **Banco obsoleto** — filtrar por `Modificada` o `Eliminada` para gestionar preguntas que ya no corresponden a la fuente
- **Efecto de un período** — combinar filtros de fechas y paquete para comparar cohortes o evaluar el impacto de una actualización
