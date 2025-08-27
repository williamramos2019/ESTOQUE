# Sistema de Gestão de Estoque - Almoxarifado Digital

## Overview

A modernized PHP-based inventory management system designed for warehouse operations with enhanced SKU control, intelligent returns, XML/NFe file processing, and visual gallery features. Built as a standalone application using PHP 8.3 and SQLite for simplicity and portability.

## User Preferences

Preferred communication style: Simple, everyday language.

## System Architecture

### Frontend Architecture
**Technology Stack**: HTML5, CSS3, Vanilla JavaScript, Bootstrap 5.3
- Single-page application architecture with modal-based interactions
- Responsive design with custom CSS gradients and Bootstrap components
- Client-side state management using JavaScript objects and localStorage
- Real-time notifications via WebSocket connections
- Visual gallery system for uploaded files with thumbnail previews

### Backend Architecture
**Server Framework**: PHP 8.3 with built-in development server
- RESTful API design with JSON response format
- Functional programming approach with organized helper functions
- Comprehensive error handling with user-friendly messages
- Session-based authentication with hardcoded admin credentials
- File upload processing for XML, images, and documents

### Data Storage Solution
**Database**: SQLite 3 for lightweight, portable data storage
- Core tables: Companies, Movements, Inventory, Logs
- PDO-based data access with prepared statements for security
- Automatic schema migration and table creation
- Real-time inventory tracking with SKU-level granularity

### Key Features & Business Logic
**Inventory Management**: 
- Individual SKU tracking per product with automatic quantity updates
- Movement-based inventory calculations (in/out transactions)
- Smart return system showing available materials per company

**File Processing**:
- XML/NFe parsing with automatic product data extraction
- Organized file upload system with type validation
- Visual gallery with thumbnail generation for images

**Real-time Features**:
- WebSocket client for live notifications
- Periodic data refresh and synchronization
- Notification management system with read/unread states

### Authentication & Security
**Authentication Method**: Session-based with admin/admin123 credentials
- Single admin user authorization model
- PHP session management with timeout handling
- Input validation and file type restrictions for uploads
- CSRF protection through session tokens

## External Dependencies

### PHP Libraries (Composer)
- **PHPMailer 6.8**: Email notification system
- **TCPDF 6.6**: PDF report generation
- **Respect/Validation 2.2**: Input validation framework
- **Predis 2.2**: Redis client for caching (if implemented)
- **Ratchet/Pawl 0.4**: WebSocket client functionality
- **Monolog 3.4**: Logging and error tracking

### Development Tools
- **PHPUnit 10.3**: Unit testing framework for quality assurance

### Frontend Dependencies
- **Bootstrap 5.3**: UI framework for responsive design
- **Font Awesome**: Icon library for enhanced visual interface

### Runtime Requirements
- **PHP 8.3+**: Core runtime environment
- **PDO Extension**: Database connectivity
- **SQLite3 Extension**: Database engine support
- **WebSocket Support**: Real-time communication capabilities

### File System Dependencies
- Automatic SQLite database file creation on installation
- File upload directory structure for organized asset management
- Thumbnail generation system for image gallery features