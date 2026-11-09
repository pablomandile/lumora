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

Abrí una terminal **como administrador** y ejecutá (ajustá las rutas):

```
schtasks /Create /TN "Lumora ComfyUI" /SC ONLOGON /RL HIGHEST /TR "C:\lumora\gpu_worker\iniciar-comfyui.bat"
schtasks /Create /TN "Lumora Gateway" /SC ONLOGON /RL HIGHEST /TR "C:\lumora\gpu_worker\iniciar-gateway.bat" /DELAY 0000:30
```

Arrancan cuando iniciás sesión. El gateway espera 30 segundos para que ComfyUI llegue a
levantar. Si la PC tiene que funcionar sin que nadie inicie sesión, configurá el inicio de sesión
automático de Windows (`netplwiz`).

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

| Método | Ruta               | Para qué                                                                                                                                              |
| ------ | ------------------ | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| GET    | `/health`          | estado de ComfyUI, placa, VRAM, cola y Ollama                                                                                                         |
| GET    | `/models`          | modelos disponibles y sus límites                                                                                                                     |
| POST   | `/generate`        | `{prompt, width, height, seed?, steps?, model?}` → 202 con `id`. 422 si el pedido es inválido, 429 si la cola está llena, 503 si ComfyUI está cerrado |
| GET    | `/jobs/{id}`       | `status` (queued/running/done/failed), `position`, `progress` (estimado), `error`                                                                     |
| GET    | `/jobs/{id}/image` | el PNG (header `X-Seed`)                                                                                                                              |
| DELETE | `/jobs/{id}`       | borra la imagen de la PC, cuando Lumora ya la guardó                                                                                                  |

- `width` y `height` van de 256 a 2048 y tienen que ser múltiplos de 16.
- Z-Image Turbo usa por defecto 9 pasos, cfg 1, sampler `euler`, scheduler `simple` y shift 3.
  Con cfg 1 el prompt negativo no tiene efecto.
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
