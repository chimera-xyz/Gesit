# GESIT - General Enterprise Service & IT

## Deskripsi Sistem

GESIT adalah sistem manajemen approval form internal yang dikembangkan untuk Yuli Sekuritas Indonesia. Sistem ini menyediakan platform terpadu untuk pengelolaan form, workflow approval, tanda tangan digital, dan pelaporan PDF yang profesional.

## Fitur Utama

### 1. Form Builder Drag-and-Drop
- Interface pembuatan form intuitif dengan drag-and-drop (mirip Typeform/JotForm)
- Konfigurasi field form dinamis melalui JSON
- Support berbagai tipe field: text, email, number, textarea, select, checkbox, radio, date, file
- Real-time preview dan validasi form

### 2. Sistem Workflow Configurable
- Workflow berbasis database (bukan hardcoded)
- Konfigurasi approval steps fleksibel melalui JSON
- Dukungan untuk multiple roles per user
- Automatic workflow progression berdasarkan approval
- Visual timeline progress approval

### 3. Multi-Role User Management
- 5 Role System: Employee, IT Staff, Operational Director, Accounting, Admin
- Support untuk multiple roles per user
- Permission-based access control dengan Spatie Laravel Permission
- Granular permissions untuk setiap fitur

### 4. Digital Signature System
- Canvas-based signature drawing dengan smooth strokes
- Upload signature dengan format PNG/JPEG
- SHA-256 hash verification untuk authenticity
- Timestamp dan metadata embedding
- Background removal dan quality enhancement
- User signature management

### 5. Professional PDF Generation
- PDF template company-branded dengan Laravel DomPDF
- Automatic form data population
- Approval timeline dengan signature integration
- Status badges dan verification indicators
- Download dan preview functionality

### 6. Role-Based Dashboards
- **Employee Dashboard**: Form submission tracking, status monitoring
- **IT Staff Dashboard**: Review queue, technical approvals
- **Operational Director Dashboard**: High-level approvals, oversight
- **Accounting Dashboard**: Financial approvals, processing
- **Admin Dashboard**: System management, user management

### 7. In-App Notification System
- Real-time notification untuk approval requests
- Read/unread status tracking
- Notification types: form_submitted, approval_needed, status_changed, signature_required
- Bulk actions (mark all as read)

### 8. Hardware/Software Procurement Form
- Pre-configured form untuk kebutuhan procurement
- 8 custom fields untuk data lengkap
- 5-step workflow untuk comprehensive approval
- Urgency dan cost estimation tracking

## Teknologi yang Digunakan

### Backend
- **Framework**: Laravel 12 dengan PHP 8.x
- **Database**: MySQL/MariaDB
- **Authentication**: Laravel Sanctum dengan JWT tokens
- **Permissions**: Spatie Laravel Permission
- **PDF Generation**: Laravel DomPDF
- **Image Processing**: Intervention Image
- **File Storage**: Laravel Filesystem

### Frontend
- **Framework**: Vue 3 dengan Composition API
- **State Management**: Pinia
- **Routing**: Vue Router 4
- **Styling**: TailwindCSS dengan custom design system
- **HTTP Client**: Axios
- **Build Tool**: Vite

## Instalasi

### Prerequisites
- PHP 8.2 atau lebih tinggi
- Composer
- Node.js 16+ dan npm
- MySQL/MariaDB 8.0+
- Apache/Nginx web server

### Setup Backend
```bash
# Install dependencies
composer install

# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Setup database di .env
# DB_CONNECTION=mysql
# DB_DATABASE=gesit
# DB_USERNAME=root
# DB_PASSWORD=your_password

# Run migrations
php artisan migrate

# Seed database dengan data awal
php artisan db:seed

# Create storage link
php artisan storage:link

# Start development server
php artisan serve
```

### Setup Frontend
```bash
# Install dependencies
npm install

# Start development server
npm run dev

# Build untuk production
npm run build
```

## Konfigurasi Default

### Roles dan Permissions
**Roles:**
- Employee: Form submission, view own submissions
- IT Staff: Technical review, approval
- Operational Director: Strategic approval, oversight
- Accounting: Financial processing, approval
- Admin: System management, full access

**Permissions:**
- view forms
- create forms
- edit forms
- delete forms
- submit forms
- view submissions
- approve forms
- reject forms
- create signatures
- manage workflows

### Workflow Default
**Hardware/Software Procurement:**
1. IT Review (IT Staff)
2. Director Approval (Operational Director)
3. Financial Processing (Accounting)
4. Final Approval (Director)
5. Completion (Auto)

**Password Reset:**
1. IT Verification (IT Staff)
2. Security Approval (IT Manager)
3. Completion (Auto)

## API Endpoints

### Authentication
- `POST /api/auth/login` - User login
- `POST /api/auth/register` - User registration
- `POST /api/auth/logout` - User logout
- `GET /api/user` - Get current user

### Forms Management
- `GET /api/forms` - List all forms
- `GET /api/forms/{id}` - Get form details
- `POST /api/forms` - Create new form (Admin only)
- `PUT /api/forms/{id}` - Update form (Admin only)
- `DELETE /api/forms/{id}` - Delete form (Admin only)

### Submissions
- `GET /api/submissions` - List submissions with filtering
- `POST /api/submissions/create` - Create new submission
- `GET /api/submissions/{id}` - Get submission details
- `POST /api/submissions/{id}/approve` - Approve submission
- `POST /api/submissions/{id}/reject` - Reject submission

### Signatures
- `POST /api/signature/draw` - Save drawn signature
- `POST /api/signature/upload` - Upload signature image
- `GET /api/signature/verify/{id}` - Verify signature
- `GET /api/signature/user-signatures` - Get user signatures
- `DELETE /api/signature/{id}` - Delete signature

### PDF Generation
- `POST /api/pdf/generate/{id}` - Generate PDF
- `GET /api/pdf/preview/{id}` - Preview PDF
- `GET /api/pdf/download/{id}` - Download PDF

### Notifications
- `GET /api/notifications` - Get user notifications
- `POST /api/notifications/{id}/read` - Mark as read
- `POST /api/notifications/read-all` - Mark all as read
- `DELETE /api/notifications/{id}` - Delete notification
- `GET /api/notifications/unread-count` - Get unread count

### User Management
- `GET /api/user/profile` - Get user profile
- `PUT /api/user/profile` - Update profile
- `PUT /api/user/change-password` - Change password

### Workflows
- `GET /api/workflows` - List workflows
- `POST /api/workflows` - Create workflow (Admin only)
- `PUT /api/workflows/{id}` - Update workflow (Admin only)
- `DELETE /api/workflows/{id}` - Delete workflow (Admin only)

## Struktur Database

### Tables Utama
- **users**: User data dengan multi-role support
- **roles**: Role definitions
- **permissions**: Permission definitions
- **model_has_permissions**: User-permission mapping
- **model_has_roles**: User-role mapping
- **role_has_permissions**: Role-permission mapping
- **workflows**: Workflow configurations
- **forms**: Form definitions
- **form_submissions**: Form submissions
- **approval_steps**: Approval step tracking
- **signatures**: Digital signature storage
- **notifications**: In-app notifications

## Security

### Authentication & Authorization
- JWT token-based authentication
- Role-based access control
- Permission-based feature access
- CSRF protection
- Rate limiting

### Data Protection
- Password hashing dengan bcrypt
- SHA-256 signature verification
- Input validation dan sanitization
- SQL injection prevention
- XSS protection

## Deployment

### Production Setup
1. Configure environment variables
2. Set proper file permissions
3. Configure SSL certificates
4. Setup database backups
5. Configure cron jobs untuk maintenance
6. Setup monitoring dan logging
7. Optimize assets: `php artisan optimize` && `npm run build`

## Testing

### Manual Testing Checklist
- [ ] User registration dan login
- [ ] Form submission dengan berbagai field types
- [ ] Workflow progression approval steps
- [ ] Digital signature drawing dan upload
- [ ] PDF generation dan download
- [ ] Notification delivery dan marking
- [ ] Role-based access control
- [ ] Admin form creation
- [ ] Approval/rejection flows
- [ ] Multi-user concurrent submissions

### Test Accounts Default
**Admin:**
- Email: admin@gesit.com
- Password: admin123
- Roles: Admin, IT Staff

**Employee:**
- Email: employee@gesit.com
- Password: employee123
- Role: Employee

**IT Staff:**
- Email: it@gesit.com
- Password: it123
- Role: IT Staff

## Troubleshooting

### Issues Umum
**File permission errors:**
```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

**Storage link issues:**
```bash
php artisan storage:link
# atau
rm -rf public/storage
php artisan storage:link
```

**Database connection errors:**
- Cek .env configuration
- Pastikan MySQL service running
- Verify database credentials

**Asset build issues:**
```bash
npm install
npm run build
php artisan view:clear
php artisan config:clear
php artisan cache:clear
```

## Support & Maintenance

Untuk dukungan teknis dan maintenance:
- Email: support@gesit.com
- Documentation: /docs
- Version: 1.0.0
- Last Updated: April 2026

## License & Copyright

© 2026 GESIT - Yuli Sekuritas Indonesia
All Rights Reserved.

---

**Developed with ❤️ for Yuli Sekuritas Indonesia**
