# 📁 M.O.C.O. - Ministerio Oficial de Copias y Organización

**"Se pega a tus archivos… y no los suelta."**

Sistema de gestión de archivos autohospedado con interfaz web, diseñado para usuarios que quieren control total sobre sus datos sin depender de servicios externos ni nubes comerciales.

## 🎯 Concepto

M.O.C.O. es un gestor privado de archivos personales pensado para:

- ✅ Autohospedado (ideal para NAS o servidor casero)
- ✅ Control completo del usuario (sin terceros ni telemetría)
- ✅ Interfaz web minimalista y funcional
- ✅ Organización local o en red
- ✅ Gestión de usuarios y permisos compartidos

## ✨ Características

- 🔐 **Autenticación segura** - Registro y login con JWT
- 📂 **Gestión de archivos** - Subir, descargar, eliminar archivos
- 📁 **Organización con carpetas** - Crea tu propia estructura
- 🔗 **Carpetas compartidas** - Comparte con permisos de lectura/escritura
- 🎨 **Interfaz moderna** - Diseño responsive y minimalista
- 🖱️ **Drag & Drop** - Arrastra archivos para subirlos
- 🔍 **Búsqueda** - Encuentra archivos rápidamente
- 📊 **Info de almacenamiento** - Visualiza el espacio utilizado
- 👥 **Multi-usuario** - Cada usuario tiene su espacio privado

## 🚀 Instalación Rápida

### Requisitos

- Python 3.8 o superior
- pip

### Pasos de instalación

1. **Clonar el repositorio**
```bash
git clone https://github.com/matatunos/MOCO.git
cd MOCO
```

2. **Crear y activar entorno virtual**
```bash
# Windows
python -m venv venv
venv\Scripts\activate

# Linux/Mac
python -m venv venv
source venv/bin/activate
```

3. **Instalar dependencias**
```bash
pip install -r requirements.txt
```

4. **Configurar variables de entorno**
```bash
# Copiar el archivo de ejemplo
copy .env.example .env

# Editar .env y cambiar las claves secretas
```

5. **Inicializar la base de datos**
```bash
cd backend
python -c "from app import init_db; init_db()"
```

6. **Ejecutar el servidor**
```bash
python app.py
```

7. **Abrir la aplicación**
- Backend API: http://localhost:5000
- Frontend: Abre `frontend/index.html` en tu navegador

## 🐳 Instalación con Docker

```bash
# Construir y ejecutar con Docker Compose
docker-compose up --build

# La aplicación estará disponible en http://localhost:5000
```

## 📖 Uso

### 1. Registrar una cuenta

1. Abre el frontend en tu navegador
2. Haz clic en "Registrarse"
3. Completa el formulario
4. Haz clic en "Crear Cuenta"

### 2. Iniciar sesión

1. Ingresa tu usuario y contraseña
2. Accede a tu espacio personal

### 3. Gestión de archivos

- **Subir**: Arrastra archivos o usa el botón "Subir Archivo"
- **Descargar**: Haz clic en el archivo
- **Eliminar**: Clic derecho → Eliminar
- **Organizar**: Crea carpetas para estructurar tu contenido

### 4. Compartir carpetas

1. Clic derecho en carpeta → "Compartir"
2. Ingresa nombre de usuario
3. Define permisos (lectura o lectura/escritura)
4. Confirma

## 🏗️ Estructura del Proyecto

```
MOCO/
├── backend/
│   ├── app.py          # Aplicación Flask principal
│   └── models.py       # Modelos de base de datos
├── frontend/
│   ├── index.html      # Interfaz de usuario
│   ├── style.css       # Estilos CSS
│   └── app.js          # Lógica JavaScript
├── uploads/            # Archivos subidos (gitignored)
├── .env.example        # Variables de entorno
├── requirements.txt    # Dependencias Python
├── Dockerfile          # Configuración Docker
└── docker-compose.yml  # Orquestación Docker
```

## 🔧 API Endpoints

### Autenticación
- `POST /api/auth/register` - Registrar usuario
- `POST /api/auth/login` - Iniciar sesión
- `GET /api/auth/me` - Info usuario actual

### Archivos
- `GET /api/files` - Listar archivos
- `POST /api/files/upload` - Subir archivo
- `GET /api/files/<id>/download` - Descargar
- `DELETE /api/files/<id>` - Eliminar

### Carpetas
- `POST /api/folders` - Crear carpeta
- `DELETE /api/folders/<id>` - Eliminar
- `POST /api/folders/<id>/share` - Compartir
- `GET /api/folders/<id>/shares` - Listar compartidos
- `DELETE /api/shares/<id>` - Eliminar compartido

## 🔒 Seguridad

- Contraseñas hasheadas con bcrypt
- Autenticación JWT
- Validación de permisos
- Sanitización de nombres
- Límite de tamaño (100MB)
- Sin telemetría ni terceros

## 🛠️ Tecnologías

**Backend**: Flask, SQLAlchemy, JWT, bcrypt  
**Frontend**: HTML5, CSS3, JavaScript Vanilla  
**Base de datos**: SQLite (desarrollo), compatible con PostgreSQL/MySQL

## 📝 Configuración (.env)

```env
SECRET_KEY=cambiar-en-produccion
JWT_SECRET_KEY=cambiar-en-produccion
DATABASE_URL=sqlite:///moco.db
UPLOAD_FOLDER=uploads
MAX_CONTENT_LENGTH=104857600
```

## 🗺️ Roadmap

- [ ] Previsualización de imágenes/videos
- [ ] Editor de texto integrado
- [ ] Versionado de archivos
- [ ] Papelera de reciclaje
- [ ] Sincronización automática
- [ ] Cliente de escritorio
- [ ] Cifrado de archivos
- [ ] 2FA

## 📄 Licencia

MIT License - Libertad total para tu nube personal

## 🤝 Contribuir

Fork → Branch → Commit → Push → Pull Request

---

**M.O.C.O.** - Tu nube personal que se pega a tus archivos… y no los suelta. 💚
