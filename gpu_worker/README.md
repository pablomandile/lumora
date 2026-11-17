# Lumora en la PC con la placa NVIDIA

Esta carpeta es lo que corre en la PC con la placa de video. Lumora (en Hostinger) le pide
imágenes por internet a través de un túnel de Cloudflare.

```
Lumora (Hostinger) ──HTTPS + token──> Cloudflare ──túnel──> gateway :8766 ──> ComfyUI :8188 ──> placa NVIDIA
                                                              (esta carpeta)     (Z-Image Turbo)
```

- **ComfyUI** genera las imágenes con el modelo **Z-Image Turbo**.
- **El gateway** (`lumora_gateway/`) es un programa chico en Python que:
    - pide el token,
    - arma el pedido para ComfyUI,
    - hace cola de a una imagen,
    - avisa si la placa está disponible,
    - y libera la memoria de la placa cuando no se usa, para que escribelo la tenga libre.
- **cloudflared** es el túnel: deja entrar desde internet solo a `lumora-gpu.bambudev.com.ar`,
  sin abrir puertos en el router.

Ollama no se usa para generar imágenes: su generación de imágenes solo funciona en macOS. Ollama
sigue instalado para escribelo, y el gateway le pide que suelte la placa cuando hace falta.

---

## 0. Requisitos

|                         |                                                                                      |
| ----------------------- | ------------------------------------------------------------------------------------ |
| Windows 10/11           | con los drivers de NVIDIA actualizados (GeForce Experience o nvidia.com)             |
| Placa                   | 12 GB de VRAM. Para los archivos int8 tiene que ser RTX serie 30, 40 o 50            |
| Disco                   | ~20 GB libres (ComfyUI + modelos)                                                    |
| Python 3.11 o más nuevo | https://www.python.org/downloads/ — al instalar, marcar **"Add python.exe to PATH"** |

Para saber qué placa tenés: `nvidia-smi` en una terminal.

---

## 1. Instalar ComfyUI

Hay dos formas. Con las dos, ComfyUI queda escuchando en `http://127.0.0.1:8188`, que es donde lo
busca el gateway.

### Opción A: Comfy Desktop (la que se usa en la PC de Pablo)

1. Bajá el instalador de **https://www.comfy.org/download**. Ojo: `comfyui.com` **no** es el sitio
   oficial.
2. Instalalo y creá una instancia. Cuando ofrezca bajar modelos (MOSS-Image, Nano Banana, Krea,
   Qwen…), tocá **Skip & Install**: ninguno es Z-Image, los locales no entran en 12 GB y Nano Banana
   se paga con créditos.
3. Desktop guarda todo en `%LOCALAPPDATA%\Comfy-Desktop\ComfyUI-Shared\`: los modelos en `models\`
   y las imágenes en `output\`. ComfyUI responde solo mientras la app está abierta.
   Con Desktop **no** se usa `iniciar-comfyui.bat`.

### Opción B: versión portable

1. Descargá **ComfyUI_windows_portable_nvidia.7z** de la última versión en
   https://github.com/Comfy-Org/ComfyUI/releases
2. Descomprimilo en `C:\ComfyUI_windows_portable` (con [7-Zip](https://www.7-zip.org/)).
3. Actualizalo: doble clic en `C:\ComfyUI_windows_portable\update\update_comfyui.bat`.
   Hace falta una versión reciente para que reconozca los archivos int8.
4. Para arrancarlo, ejecutá `iniciar-comfyui.bat`. Si ComfyUI quedó en otra carpeta, editá la
   línea `set COMFY_DIR=...`.

### Modelos

Descargá estos 3 archivos y ponelos en las carpetas indicadas, dentro de la carpeta `models`
(Desktop: `%LOCALAPPDATA%\Comfy-Desktop\ComfyUI-Shared\models\`; portable:
`C:\ComfyUI_windows_portable\ComfyUI\models\`):

| Archivo                                                                                                                                                                   | Carpeta            | Tamaño |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------ | ------ |
| [z_image_turbo_int8_convrot.safetensors](https://huggingface.co/Comfy-Org/z_image_turbo/resolve/main/split_files/diffusion_models/z_image_turbo_int8_convrot.safetensors) | `diffusion_models` | 6.2 GB |
| [qwen_3_4b_fp8_mixed.safetensors](https://huggingface.co/Comfy-Org/z_image_turbo/resolve/main/split_files/text_encoders/qwen_3_4b_fp8_mixed.safetensors)                  | `text_encoders`    | 5.6 GB |
| [ae.safetensors](https://huggingface.co/Comfy-Org/z_image_turbo/resolve/main/split_files/vae/ae.safetensors)                                                              | `vae`              | 0.3 GB |

> **Si la generación falla con un error de int8** (por ejemplo, porque la placa es más vieja que la
> serie 30), bajá en su lugar
> [z_image_turbo_bf16.safetensors](https://huggingface.co/Comfy-Org/z_image_turbo/resolve/main/split_files/diffusion_models/z_image_turbo_bf16.safetensors)
> (12.3 GB). Después cambiá `ZIMAGE_UNET` en el `.env` del gateway. Anda igual, pero un poco más
> lento, porque ComfyUI pasa parte del modelo a la RAM.

### Modelos para editar imágenes (Qwen Image Edit 2511)

Para editar con imágenes subidas («poné esta cara en el flyer») hace falta además:

1. El custom node **ComfyUI-GGUF**: clonalo en `custom_nodes` e instalá sus dependencias con
   el Python **de ComfyUI** (en Desktop, el `.venv` de la instancia):

    ```bat
    cd %LOCALAPPDATA%\Comfy-Desktop\ComfyUI-Installs\Lumora\ComfyUI\custom_nodes
    git clone https://github.com/city96/ComfyUI-GGUF
    ..\.venv\Scripts\python.exe -m pip install -r ComfyUI-GGUF\requirements.txt
    ```

2. Estos archivos (unos 23 GB en total):

| Archivo                                                                                                                                                                                                | Carpeta         | Tamaño  |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | --------------- | ------- |
| [qwen-image-edit-2511-Q4_K_M.gguf](https://huggingface.co/unsloth/Qwen-Image-Edit-2511-GGUF/resolve/main/qwen-image-edit-2511-Q4_K_M.gguf)                                                             | `unet`          | 12.2 GB |
| [Qwen-Image-Edit-2511-Lightning-4steps-V1.0-bf16.safetensors](https://huggingface.co/lightx2v/Qwen-Image-Edit-2511-Lightning/resolve/main/Qwen-Image-Edit-2511-Lightning-4steps-V1.0-bf16.safetensors) | `loras`         | 0.8 GB  |
| [qwen_2.5_vl_7b_fp8_scaled.safetensors](https://huggingface.co/Comfy-Org/Qwen-Image_ComfyUI/resolve/main/split_files/text_encoders/qwen_2.5_vl_7b_fp8_scaled.safetensors)                              | `text_encoders` | 9.4 GB  |
| [qwen_image_vae.safetensors](https://huggingface.co/Comfy-Org/Qwen-Image_ComfyUI/resolve/main/split_files/vae/qwen_image_vae.safetensors)                                                              | `vae`           | 0.3 GB  |

> La LoRA Lightning está **apareada con esta versión** del modelo: si cambiás uno, cambiá el otro.
> En la RTX 3060 tarda ~70 s con una imagen y ~95 s con dos (4 pasos). Usa hasta ~20 GB de RAM.

3. En el `.env` del gateway, `COMFY_INPUT_DIR` apuntando a la carpeta `input` de ComfyUI: así las
   fotos de los usuarios se borran de la PC apenas se usan. Sin esa variable quedan ahí.

### Probar ComfyUI solo

1. Abrí Comfy Desktop, o ejecutá `iniciar-comfyui.bat` si usás la portable.
2. En ComfyUI, elegí **Workflow → Browse Templates → Image → Z-Image Turbo**,
   seleccioná los archivos de arriba en los nodos de carga y apretá **Run**.
3. Si sale una imagen, ComfyUI está listo. Anotá cuántos segundos tardó.

---

## 2. Instalar el gateway

1. Copiá esta carpeta `gpu_worker` a la PC, por ejemplo en `C:\lumora\gpu_worker`.
   También podés clonar el repo y usar solo esta carpeta.
2. Doble clic en **`instalar.bat`**. Crea el entorno de Python y un archivo `.env`.
3. Generá el token:
    ```
    python -c "import secrets; print(secrets.token_urlsafe(32))"
    ```
4. Abrí `.env` y pegalo en `LUMORA_GPU_TOKEN=`. **El mismo valor** va en el `.env` de Lumora
   (`LUMORA_GPU_TOKEN`). Revisá también que `COMFY_OUTPUT_DIR` apunte a la carpeta `output` de
   tu ComfyUI.
5. Con ComfyUI abierto, ejecutá **`iniciar-gateway.bat`**.

### Probarlo en la misma PC

En otra terminal (reemplazá `TU_TOKEN`):

```
curl -H "Authorization: Bearer TU_TOKEN" http://127.0.0.1:8766/health
```

Tiene que responder `"status":"ok"` con el nombre de la placa y la VRAM. Para generar una
imagen de prueba:

```
curl -X POST -H "Authorization: Bearer TU_TOKEN" -H "Content-Type: application/json" ^
     -d "{\"prompt\":\"un faro al atardecer, fotografia\",\"width\":1024,\"height\":1024}" ^
     http://127.0.0.1:8766/generate
```

Devuelve un `id`. Consultá el estado con `curl -H "Authorization: Bearer TU_TOKEN"
http://127.0.0.1:8766/jobs/ID`. Cuando diga `"status":"done"`, bajá la imagen:

```
curl -H "Authorization: Bearer TU_TOKEN" -o prueba.png http://127.0.0.1:8766/jobs/ID/image
```

---

## 3. Que arranque solo con Windows

`iniciar-lumora.bat` arranca todo: ComfyUI en una ventana minimizada, espera a que responda (hasta
3 minutos) y recién ahí arranca el gateway en otra. Si alguno ya está corriendo, no lo abre de nuevo,
así que se puede ejecutar a mano las veces que haga falta. El túnel no hace falta: cloudflared quedó
instalado como servicio de Windows y arranca solo.

`iniciar-comfyui.bat` detecta la instalación: si existe la instancia "Lumora" de **Comfy Desktop**
la usa sin abrir la app de escritorio (con su Python y sus rutas de modelos); si no, la portable de
`COMFY_DIR`. Con ComfyUI ya levantado por este script, no lances además la instancia desde Comfy
Desktop: las dos quieren el puerto 8188.

Para arrancarlo con un clic y al iniciar sesión, la forma simple (sin permisos de administrador) es
un **acceso directo** a `iniciar-lumora.bat`, no una copia: el `.bat` busca los otros scripts en su
propia carpeta y copiado a otro lado no los encuentra. Desde PowerShell:

```powershell
$bat = 'C:\laragon\www\lumora\gpu_worker\iniciar-lumora.bat'
$sh = New-Object -ComObject WScript.Shell
foreach ($dir in 'Desktop', 'Startup') {
    $s = $sh.CreateShortcut((Join-Path ([Environment]::GetFolderPath($dir)) 'Iniciar Lumora.lnk'))
    $s.TargetPath = $bat; $s.WorkingDirectory = Split-Path $bat; $s.WindowStyle = 7
    $s.IconLocation = 'C:\laragon\www\lumora\public\favicon.ico,0'; $s.Save()
}
```

Queda uno en el Escritorio y otro en la carpeta de Inicio (`Win+R` → `shell:startup`): borrar ese
último lo saca del arranque. Después de iniciar sesión, la placa tarda hasta un minuto en verse en
verde: ComfyUI tarda en cargar, el gateway espera a que responda, y el servidor guarda el estado 30 s.

Alternativa, si hace falta que arranque con permisos elevados: una tarea programada, desde una
terminal **como administrador** (ajustá la ruta):

```
schtasks /Create /TN "Lumora" /SC ONLOGON /RL HIGHEST /DELAY 0000:20 /TR "\"C:\laragon\www\lumora\gpu_worker\iniciar-lumora.bat\""
```

Para probarla sin reiniciar: `schtasks /Run /TN "Lumora"`. Para sacarla: `schtasks /Delete /TN "Lumora" /F`.
No uses las dos formas a la vez (no rompe nada, pero el segundo arranque no hace nada).

Si la PC tiene que funcionar sin que nadie inicie sesión, configurá el inicio de sesión automático
de Windows (`netplwiz`).

---

## 4. El túnel de Cloudflare (gratis, con dirección fija)

El túnel con nombre propio es **gratis**. Lo único que pide es que el DNS de un dominio esté en
Cloudflare; para eso se usa **bambudev.com.ar**. Las webs y el mail siguen en Hostinger: solo
cambia quién responde el DNS.

### 4.1 Pasar el DNS de bambudev.com.ar a Cloudflare (una sola vez)

> ⚠️ Hacé este paso con calma: si falta un registro, puede dejar de andar un subdominio o el mail.

1. **Anotá los registros actuales.** En hPanel de Hostinger, en **Dominios → bambudev.com.ar → DNS / Nameservers**,
   sacá captura o copiá **todos** los registros: A, AAAA, CNAME, MX, TXT (SPF, DKIM, DMARC), SRV
   y los de cada subdominio.
2. Creá una cuenta en https://dash.cloudflare.com y elegí **Add a domain** → `bambudev.com.ar` →
   plan **Free**.
3. Cloudflare importa los registros solo. **Comparalos uno por uno con tu lista** y agregá los que
   falten.
    - En los registros que apuntan a Hostinger (A, CNAME de las webs), dejá la nube en **gris
      ("DNS only")**, para que Hostinger siga manejando SSL y caché como hasta ahora.
    - Los MX y TXT del mail tienen que estar idénticos.
4. Cloudflare te muestra **2 nameservers** (algo como `xxx.ns.cloudflare.com`).
5. En **https://nic.ar** → Mis dominios → bambudev.com.ar → **Delegaciones**, reemplazá los
   nameservers de Hostinger por los 2 de Cloudflare.
6. Esperá a que Cloudflare marque el dominio como **Active**. Puede tardar de minutos a 24 h.
   Después revisá que las webs de bambudev y el mail sigan andando.

### 4.2 Crear el túnel

**Método A — desde el panel (recomendado en Windows):**

1. En el panel de Cloudflare entrá a **Zero Trust → Networks → Tunnels → Create a tunnel →
   Cloudflared**. Si te pide elegir un plan de Zero Trust, elegí **Free**.
2. Nombre: `pc-gpu`. Elegí **Windows** y copiá el comando que te muestra, que es parecido a
   `cloudflared.exe service install eyJ...`.
3. En la PC, instalá cloudflared con `winget install --id Cloudflare.cloudflared`. Después, en una
   terminal **como administrador**, pegá el comando del paso anterior. Queda instalado como servicio
   de Windows y arranca solo.
4. En la pestaña **Public Hostnames** del túnel agregá:
    - `lumora-gpu` . `bambudev.com.ar` → Service **HTTP** → `127.0.0.1:8766`
    - (opcional) `escribelo-gpu` . `bambudev.com.ar` → **HTTP** → `127.0.0.1:8765`
    - `docbrainer-gpu` . `bambudev.com.ar` → **HTTP** → `localhost:11434`, con **HTTP Host Header**
      `localhost:11434` (en _Additional application settings → HTTP Settings_; si no, Ollama responde
      403). Es el Ollama de la PC para DocBrainer. Ollama no pide credenciales, así que ese hostname
      va protegido con una aplicación de **Cloudflare Access** con service token. Los pasos están en
      el README de DocBrainer.

**Método B — por línea de comandos** (si el panel te pide una tarjeta o preferís archivos):

```
cloudflared tunnel login
cloudflared tunnel create pc-gpu
cloudflared tunnel route dns pc-gpu lumora-gpu.bambudev.com.ar
cloudflared tunnel route dns pc-gpu escribelo-gpu.bambudev.com.ar
```

Copiá `cloudflared/config.yml.example` a `C:\Users\<vos>\.cloudflared\config.yml`, completá el
ID del túnel y probalo con `cloudflared tunnel run pc-gpu`. Para que arranque solo, sumalo a
`schtasks` igual que el gateway, con `/TR "cloudflared tunnel run pc-gpu"`.

### 4.3 Probar desde afuera

Desde cualquier compu o celular:

```
curl -H "Authorization: Bearer TU_TOKEN" https://lumora-gpu.bambudev.com.ar/health
```

Después, en el `.env` de Lumora (Hostinger):

```
LUMORA_GPU_URL=https://lumora-gpu.bambudev.com.ar
LUMORA_GPU_TOKEN=el-mismo-token
```

**Escribelo:** si agregaste `escribelo-gpu`, poné `https://escribelo-gpu.bambudev.com.ar` como URL
del worker en los Ajustes de admin de escribelo. Así deja de depender del quick tunnel que cambia de
dirección. Es opcional: el quick tunnel de escribelo puede seguir como está.

---

## 5. Convivencia con escribelo

Las dos apps usan la misma placa de 12 GB:

- El gateway genera **de a una imagen**. Los pedidos que llegan mientras tanto esperan en cola.
- Si antes de generar quedan menos de `MIN_FREE_VRAM_GB` libres, el gateway le pide a Ollama que
  suelte los modelos que tiene cargados (`UNLOAD_OLLAMA=true`). Whisper no se toca.
- Después de `IDLE_FREE_SECONDS` (5 minutos) sin generar, ComfyUI descarga Z-Image de la placa.
- `/health` informa la VRAM libre y los modelos de Ollama cargados. Lumora usa ese dato para mostrar
  si la placa está "disponible", "ocupada" o "apagada".

## 6. Referencia

### API (todas las rutas piden `Authorization: Bearer <token>`)

| Método | Ruta               | Para qué                                                                                                                                                       |
| ------ | ------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| GET    | `/health`          | estado de ComfyUI, placa, VRAM, cola y Ollama                                                                                                                  |
| GET    | `/models`          | modelos disponibles y sus límites                                                                                                                              |
| POST   | `/inputs`          | sube una imagen para editar (el cuerpo son los bytes; PNG, JPEG o WebP) → `{name}`. El nombre es el hash: subirla dos veces no duplica nada                    |
| POST   | `/generate`        | `{prompt, width, height, seed?, steps?, model?, images?}` → 202 con `id`. 422 si el pedido es inválido, 429 si la cola está llena, 503 si ComfyUI está cerrado |
| GET    | `/jobs/{id}`       | `status` (queued/running/done/failed), `position`, `progress` (estimado), `error`                                                                              |
| GET    | `/jobs/{id}/image` | el PNG (header `X-Seed`)                                                                                                                                       |
| DELETE | `/jobs/{id}`       | borra la imagen de la PC, cuando Lumora ya la guardó                                                                                                           |

- `width` y `height` van de 256 a 2048 y tienen que ser múltiplos de 16.
- Z-Image Turbo usa por defecto 9 pasos, cfg 1, sampler `euler`, scheduler `simple` y shift 3.
  Con cfg 1 el prompt negativo no tiene efecto.
- `qwen-image-edit` necesita de 1 a 3 `images` (los `name` de `/inputs`; la primera es la que se
  edita) y usa 4 pasos. `width`/`height` es el tamaño de salida: Lumora lo calcula con la
  proporción de la imagen 1, a ~1 MP. Al pedido se le antepone una instrucción para que conserve
  los textos y el resto de la imagen (`EDIT_PREFIX` en `workflows.py`).
- `/generate` responde enseguida y Lumora consulta el estado. Así ningún pedido queda abierto más de
  100 segundos, que es el límite de Cloudflare.

### Variables del `.env`

Están todas explicadas en `.env.example`.

### Desarrollo

```
python -m venv .venv && .venv\Scripts\pip install -r requirements-dev.txt
.venv\Scripts\python -m pytest
```

Los tests usan un ComfyUI y un Ollama simulados, así que no hace falta tener la placa.

### Problemas comunes

| Síntoma                                    | Causa probable                                                                         |
| ------------------------------------------ | -------------------------------------------------------------------------------------- |
| `/health` → `"comfyui":"sin respuesta"`    | ComfyUI no está abierto, o no está en el puerto 8188                                   |
| 401                                        | El token de Lumora y el del `.env` de la PC no coinciden                               |
| 500 "LUMORA_GPU_TOKEN no está configurado" | Falta completar el `.env`                                                              |
| Error "int8" o "unsupported" al generar    | La placa no soporta int8: usá el archivo bf16 (ver sección 1)                          |
| `CUDA out of memory`                       | escribelo está usando mucha VRAM. Bajá el tamaño de la imagen o esperá                 |
| 502/530 desde afuera, pero local anda      | cloudflared caído: revisá el servicio, o el estado del túnel en el panel de Cloudflare |
