"""
Main Flask application for MOCO File Manager
"""
import os
from flask import Flask, request, jsonify, send_file, send_from_directory
from flask_cors import CORS
from flask_jwt_extended import JWTManager, create_access_token, jwt_required, get_jwt_identity
from werkzeug.utils import secure_filename
from dotenv import load_dotenv
from datetime import timedelta
import mimetypes

from models import db, User, File, Folder, SharedFolder

# Load environment variables
load_dotenv()

# Get the parent directory (MOCO root)
BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

app = Flask(__name__, static_folder=os.path.join(BASE_DIR, 'frontend'), static_url_path='')
app.config['SECRET_KEY'] = os.getenv('SECRET_KEY', 'dev-secret-key')
app.config['JWT_SECRET_KEY'] = os.getenv('JWT_SECRET_KEY', 'jwt-secret-key')
app.config['SQLALCHEMY_DATABASE_URI'] = os.getenv('DATABASE_URL', 'sqlite:///moco.db')
app.config['SQLALCHEMY_TRACK_MODIFICATIONS'] = False
app.config['UPLOAD_FOLDER'] = os.getenv('UPLOAD_FOLDER', 'uploads')
app.config['MAX_CONTENT_LENGTH'] = int(os.getenv('MAX_CONTENT_LENGTH', 104857600))  # 100MB
app.config['JWT_ACCESS_TOKEN_EXPIRES'] = timedelta(hours=24)
app.config['JWT_TOKEN_LOCATION'] = ['headers']
app.config['JWT_HEADER_NAME'] = 'Authorization'
app.config['JWT_HEADER_TYPE'] = 'Bearer'

# Initialize extensions
db.init_app(app)
jwt = JWTManager(app)
CORS(app, resources={r"/api/*": {"origins": "*", "allow_headers": ["Content-Type", "Authorization"]}})

# Create upload folder if it doesn't exist
os.makedirs(app.config['UPLOAD_FOLDER'], exist_ok=True)


@app.route('/api/auth/register', methods=['POST'])
def register():
    """Register a new user"""
    data = request.get_json()
    
    if not data or not data.get('username') or not data.get('email') or not data.get('password'):
        return jsonify({'error': 'Missing required fields'}), 400
    
    if User.query.filter_by(username=data['username']).first():
        return jsonify({'error': 'Username already exists'}), 400
    
    if User.query.filter_by(email=data['email']).first():
        return jsonify({'error': 'Email already exists'}), 400
    
    user = User(username=data['username'], email=data['email'])
    user.set_password(data['password'])
    
    db.session.add(user)
    db.session.commit()
    
    # Create root folder for user
    root_folder = Folder(
        name='root',
        path='/',
        user_id=user.id
    )
    db.session.add(root_folder)
    db.session.commit()
    
    return jsonify({
        'message': 'User registered successfully',
        'user': user.to_dict()
    }), 201


@app.route('/api/auth/login', methods=['POST'])
def login():
    """Login user and return JWT token"""
    data = request.get_json()
    
    if not data or not data.get('username') or not data.get('password'):
        return jsonify({'error': 'Missing username or password'}), 400
    
    user = User.query.filter_by(username=data['username']).first()
    
    if not user or not user.check_password(data['password']):
        return jsonify({'error': 'Invalid username or password'}), 401
    
    access_token = create_access_token(identity=user.id)
    
    return jsonify({
        'access_token': access_token,
        'user': user.to_dict()
    }), 200


@app.route('/api/auth/me', methods=['GET'])
@jwt_required()
def get_current_user():
    """Get current user info"""
    user_id = get_jwt_identity()
    user = User.query.get(user_id)
    
    if not user:
        return jsonify({'error': 'User not found'}), 404
    
    return jsonify(user.to_dict()), 200


@app.route('/api/files', methods=['GET'])
@jwt_required()
def list_files():
    """List files and folders for current user"""
    user_id = get_jwt_identity()
    folder_id = request.args.get('folder_id', type=int)
    
    # Get user's own files and folders
    if folder_id:
        files = File.query.filter_by(user_id=user_id, folder_id=folder_id).all()
        folders = Folder.query.filter_by(user_id=user_id, parent_id=folder_id).all()
    else:
        files = File.query.filter_by(user_id=user_id, folder_id=None).all()
        folders = Folder.query.filter_by(user_id=user_id, parent_id=None).filter(Folder.name != 'root').all()
    
    # Get shared folders
    shared = SharedFolder.query.filter_by(shared_with_user_id=user_id).all()
    shared_folders = [Folder.query.get(s.folder_id) for s in shared]
    
    items = []
    for folder in folders:
        folder_dict = folder.to_dict()
        folder_dict['shared'] = False
        items.append(folder_dict)
    
    for folder in shared_folders:
        if folder and (not folder_id or folder.parent_id == folder_id):
            folder_dict = folder.to_dict()
            folder_dict['shared'] = True
            items.append(folder_dict)
    
    for file in files:
        items.append(file.to_dict())
    
    return jsonify(items), 200


@app.route('/api/files/upload', methods=['POST'])
@jwt_required()
def upload_file():
    """Upload a new file"""
    user_id = get_jwt_identity()
    
    if 'file' not in request.files:
        return jsonify({'error': 'No file provided'}), 400
    
    file = request.files['file']
    folder_id = request.form.get('folder_id', type=int)
    
    if file.filename == '':
        return jsonify({'error': 'No file selected'}), 400
    
    if file:
        filename = secure_filename(file.filename)
        
        # Create user-specific directory
        user_dir = os.path.join(app.config['UPLOAD_FOLDER'], str(user_id))
        os.makedirs(user_dir, exist_ok=True)
        
        # Generate unique filename
        base, ext = os.path.splitext(filename)
        counter = 1
        unique_filename = filename
        while os.path.exists(os.path.join(user_dir, unique_filename)):
            unique_filename = f"{base}_{counter}{ext}"
            counter += 1
        
        filepath = os.path.join(user_dir, unique_filename)
        file.save(filepath)
        
        # Get file size and mime type
        file_size = os.path.getsize(filepath)
        mime_type = mimetypes.guess_type(filename)[0] or 'application/octet-stream'
        
        # Save to database
        new_file = File(
            name=unique_filename,
            original_name=filename,
            size=file_size,
            mime_type=mime_type,
            storage_path=filepath,
            folder_id=folder_id,
            user_id=user_id
        )
        
        db.session.add(new_file)
        db.session.commit()
        
        return jsonify({
            'message': 'File uploaded successfully',
            'file': new_file.to_dict()
        }), 201


@app.route('/api/files/<int:file_id>/download', methods=['GET'])
@jwt_required()
def download_file(file_id):
    """Download a file"""
    user_id = get_jwt_identity()
    file = File.query.get(file_id)
    
    if not file:
        return jsonify({'error': 'File not found'}), 404
    
    # Check if user owns the file or has access through shared folder
    if file.user_id != user_id:
        if file.folder_id:
            shared = SharedFolder.query.filter_by(
                folder_id=file.folder_id,
                shared_with_user_id=user_id
            ).first()
            if not shared:
                return jsonify({'error': 'Access denied'}), 403
        else:
            return jsonify({'error': 'Access denied'}), 403
    
    return send_file(file.storage_path, as_attachment=True, download_name=file.original_name)


@app.route('/api/files/<int:file_id>', methods=['DELETE'])
@jwt_required()
def delete_file(file_id):
    """Delete a file"""
    user_id = get_jwt_identity()
    file = File.query.get(file_id)
    
    if not file:
        return jsonify({'error': 'File not found'}), 404
    
    if file.user_id != user_id:
        return jsonify({'error': 'Access denied'}), 403
    
    # Delete physical file
    if os.path.exists(file.storage_path):
        os.remove(file.storage_path)
    
    db.session.delete(file)
    db.session.commit()
    
    return jsonify({'message': 'File deleted successfully'}), 200


@app.route('/api/folders', methods=['POST'])
@jwt_required()
def create_folder():
    """Create a new folder"""
    user_id = get_jwt_identity()
    data = request.get_json()
    
    if not data or not data.get('name'):
        return jsonify({'error': 'Folder name is required'}), 400
    
    parent_id = data.get('parent_id')
    name = data['name']
    
    # Build path
    if parent_id:
        parent = Folder.query.get(parent_id)
        if not parent or parent.user_id != user_id:
            return jsonify({'error': 'Parent folder not found'}), 404
        path = f"{parent.path}{name}/"
    else:
        path = f"/{name}/"
    
    folder = Folder(
        name=name,
        path=path,
        parent_id=parent_id,
        user_id=user_id
    )
    
    db.session.add(folder)
    db.session.commit()
    
    return jsonify({
        'message': 'Folder created successfully',
        'folder': folder.to_dict()
    }), 201


@app.route('/api/folders/<int:folder_id>', methods=['DELETE'])
@jwt_required()
def delete_folder(folder_id):
    """Delete a folder"""
    user_id = get_jwt_identity()
    folder = Folder.query.get(folder_id)
    
    if not folder:
        return jsonify({'error': 'Folder not found'}), 404
    
    if folder.user_id != user_id:
        return jsonify({'error': 'Access denied'}), 403
    
    if folder.name == 'root':
        return jsonify({'error': 'Cannot delete root folder'}), 400
    
    # Delete all files in folder
    for file in folder.files:
        if os.path.exists(file.storage_path):
            os.remove(file.storage_path)
    
    db.session.delete(folder)
    db.session.commit()
    
    return jsonify({'message': 'Folder deleted successfully'}), 200


@app.route('/api/folders/<int:folder_id>/share', methods=['POST'])
@jwt_required()
def share_folder(folder_id):
    """Share a folder with another user"""
    user_id = get_jwt_identity()
    data = request.get_json()
    
    folder = Folder.query.get(folder_id)
    if not folder or folder.user_id != user_id:
        return jsonify({'error': 'Folder not found or access denied'}), 404
    
    username = data.get('username')
    permission = data.get('permission', 'read')
    
    if not username:
        return jsonify({'error': 'Username is required'}), 400
    
    target_user = User.query.filter_by(username=username).first()
    if not target_user:
        return jsonify({'error': 'User not found'}), 404
    
    if target_user.id == user_id:
        return jsonify({'error': 'Cannot share with yourself'}), 400
    
    # Check if already shared
    existing = SharedFolder.query.filter_by(
        folder_id=folder_id,
        shared_with_user_id=target_user.id
    ).first()
    
    if existing:
        return jsonify({'error': 'Folder already shared with this user'}), 400
    
    shared = SharedFolder(
        folder_id=folder_id,
        shared_with_user_id=target_user.id,
        permission=permission
    )
    
    db.session.add(shared)
    db.session.commit()
    
    return jsonify({
        'message': 'Folder shared successfully',
        'shared': shared.to_dict()
    }), 201


@app.route('/api/folders/<int:folder_id>/shares', methods=['GET'])
@jwt_required()
def get_folder_shares(folder_id):
    """Get all shares for a folder"""
    user_id = get_jwt_identity()
    folder = Folder.query.get(folder_id)
    
    if not folder or folder.user_id != user_id:
        return jsonify({'error': 'Folder not found or access denied'}), 404
    
    shares = SharedFolder.query.filter_by(folder_id=folder_id).all()
    
    return jsonify([share.to_dict() for share in shares]), 200


@app.route('/api/shares/<int:share_id>', methods=['DELETE'])
@jwt_required()
def remove_share(share_id):
    """Remove a folder share"""
    user_id = get_jwt_identity()
    share = SharedFolder.query.get(share_id)
    
    if not share:
        return jsonify({'error': 'Share not found'}), 404
    
    folder = Folder.query.get(share.folder_id)
    if folder.user_id != user_id:
        return jsonify({'error': 'Access denied'}), 403
    
    db.session.delete(share)
    db.session.commit()
    
    return jsonify({'message': 'Share removed successfully'}), 200


@app.route('/api/health', methods=['GET'])
def health_check():
    """Health check endpoint"""
    return jsonify({'status': 'ok'}), 200


@app.route('/')
def index():
    """Serve the frontend"""
    return send_from_directory(app.static_folder, 'index.html')


@app.route('/<path:path>')
def serve_static(path):
    """Serve static files"""
    return send_from_directory(app.static_folder, path)


def init_db():
    """Initialize database"""
    with app.app_context():
        db.create_all()
        print("Database initialized successfully!")


if __name__ == '__main__':
    init_db()
    app.run(debug=True, host='0.0.0.0', port=5000)
