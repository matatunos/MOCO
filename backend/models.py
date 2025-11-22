"""
Database models for MOCO File Manager
"""
from datetime import datetime
from flask_sqlalchemy import SQLAlchemy
from werkzeug.security import generate_password_hash, check_password_hash

db = SQLAlchemy()


class User(db.Model):
    """User model for authentication and file ownership"""
    __tablename__ = 'users'
    
    id = db.Column(db.Integer, primary_key=True)
    username = db.Column(db.String(80), unique=True, nullable=False, index=True)
    email = db.Column(db.String(120), unique=True, nullable=False, index=True)
    password_hash = db.Column(db.String(255), nullable=False)
    created_at = db.Column(db.DateTime, default=datetime.utcnow)
    
    # Relationships
    files = db.relationship('File', backref='owner', lazy=True, cascade='all, delete-orphan')
    folders = db.relationship('Folder', backref='owner', lazy=True, cascade='all, delete-orphan')
    
    def set_password(self, password):
        """Hash and set the user's password"""
        self.password_hash = generate_password_hash(password)
    
    def check_password(self, password):
        """Check if provided password matches the hash"""
        return check_password_hash(self.password_hash, password)
    
    def to_dict(self):
        """Convert user to dictionary"""
        return {
            'id': self.id,
            'username': self.username,
            'email': self.email,
            'created_at': self.created_at.isoformat()
        }


class Folder(db.Model):
    """Folder model for organizing files"""
    __tablename__ = 'folders'
    
    id = db.Column(db.Integer, primary_key=True)
    name = db.Column(db.String(255), nullable=False)
    path = db.Column(db.String(500), nullable=False)
    parent_id = db.Column(db.Integer, db.ForeignKey('folders.id'), nullable=True)
    user_id = db.Column(db.Integer, db.ForeignKey('users.id'), nullable=False)
    created_at = db.Column(db.DateTime, default=datetime.utcnow)
    updated_at = db.Column(db.DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)
    
    # Relationships
    files = db.relationship('File', backref='folder', lazy=True)
    children = db.relationship('Folder', backref=db.backref('parent', remote_side=[id]), lazy=True)
    shared_with = db.relationship('SharedFolder', backref='folder', lazy=True, cascade='all, delete-orphan')
    
    def to_dict(self):
        """Convert folder to dictionary"""
        return {
            'id': self.id,
            'name': self.name,
            'path': self.path,
            'parent_id': self.parent_id,
            'user_id': self.user_id,
            'created_at': self.created_at.isoformat(),
            'updated_at': self.updated_at.isoformat(),
            'type': 'folder'
        }


class File(db.Model):
    """File model for storing file metadata"""
    __tablename__ = 'files'
    
    id = db.Column(db.Integer, primary_key=True)
    name = db.Column(db.String(255), nullable=False)
    original_name = db.Column(db.String(255), nullable=False)
    size = db.Column(db.BigInteger, nullable=False)
    mime_type = db.Column(db.String(100))
    storage_path = db.Column(db.String(500), nullable=False)
    folder_id = db.Column(db.Integer, db.ForeignKey('folders.id'), nullable=True)
    user_id = db.Column(db.Integer, db.ForeignKey('users.id'), nullable=False)
    created_at = db.Column(db.DateTime, default=datetime.utcnow)
    updated_at = db.Column(db.DateTime, default=datetime.utcnow, onupdate=datetime.utcnow)
    
    def to_dict(self):
        """Convert file to dictionary"""
        return {
            'id': self.id,
            'name': self.name,
            'original_name': self.original_name,
            'size': self.size,
            'mime_type': self.mime_type,
            'folder_id': self.folder_id,
            'user_id': self.user_id,
            'created_at': self.created_at.isoformat(),
            'updated_at': self.updated_at.isoformat(),
            'type': 'file'
        }


class SharedFolder(db.Model):
    """Shared folder permissions"""
    __tablename__ = 'shared_folders'
    
    id = db.Column(db.Integer, primary_key=True)
    folder_id = db.Column(db.Integer, db.ForeignKey('folders.id'), nullable=False)
    shared_with_user_id = db.Column(db.Integer, db.ForeignKey('users.id'), nullable=False)
    permission = db.Column(db.String(20), default='read')  # 'read' or 'write'
    created_at = db.Column(db.DateTime, default=datetime.utcnow)
    
    # Relationships
    shared_with_user = db.relationship('User', backref='shared_folders', lazy=True)
    
    def to_dict(self):
        """Convert shared folder to dictionary"""
        return {
            'id': self.id,
            'folder_id': self.folder_id,
            'shared_with_user_id': self.shared_with_user_id,
            'shared_with_username': self.shared_with_user.username,
            'permission': self.permission,
            'created_at': self.created_at.isoformat()
        }
