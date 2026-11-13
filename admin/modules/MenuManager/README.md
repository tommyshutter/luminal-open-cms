# Enhanced Menu Manager

**Version:** 2025.10.24.2100  
**Location:** `/admin/menu-manager/`

## 🎯 Overview

Complete menu management system with CRUD operations, drag & drop ordering, inline editing, external URL support, and live font/style preview.

---

## 📁 File Structure

```
/admin/menu-manager/
├── index.php              # Main interface (enhanced version)
├── save.php               # Menu items save endpoint
├── handler.php            # Settings API (load/save)
├── css/
│   └── menu-manager.css   # Complete styling (552 lines)
├── js/
│   └── menu-manager.js    # Full functionality (665+ lines)
└── README.md              # This file

/admin/data/menu/
├── menu_items.json        # Menu items data
├── menu_settings.json     # Font & style settings
└── *.backup.*.json        # Automatic backups
```

---

## ✨ Features Delivered

### ✅ **CRUD Operations**
- **Create:** Add pages from pills or external links via modal
- **Read:** Load and display menu items
- **Update:** Inline editing of labels and URLs
- **Delete:** Remove items with confirmation

### ✅ **Drag to Order**
- Visual drag handles (⋮⋮)
- Smooth drag & drop reordering
- Visual feedback during dragging

### ✅ **Pills (Page Manager Integration)**
- Available pages shown as clickable pills
- Add to menu with single click
- Auto-return to pills when removed

### ✅ **Inline Editing**
- Click label to edit display text
- Click URL to edit external links
- Auto-save on blur
- Validation prevents empty values

### ✅ **External Paths**
- Modal dialog for adding external links
- Full URL editing support
- External badge indicator
- Validation for proper URL format

### ✅ **Font Manager Panel**
- **10 Settings with Live Controls:**
  1. Font Family (dropdown)
  2. Font Size (10-32px slider)
  3. Font Weight (100-900 slider)
  4. Font Style (normal/italic)
  5. Text Color (color picker)
  6. Text Hover Color (color picker)
  7. Background Color (color picker)
  8. Background Opacity (0-1 slider)
  9. Item Background (color picker)
  10. Item Hover Background (color picker)

### ✅ **Live Preview**
- Real-time preview panel
- Updates as you adjust settings
- Shows normal and hover states
- Accurate representation of final menu

### ✅ **Clean Data Organization**
- Moved to `/admin/data/menu/`
- Automatic backups with timestamps
- JSON pretty-printed for readability

---

## 🚀 Usage

### **Access the Manager**
```
https://yoursite.com/admin/menu-manager/
```

### **Add Internal Pages**
1. Look at "Available Pages" panel
2. Click any pill to add to menu
3. Items appear in menu list

### **Add External Links**
1. Click "+ External Link" button
2. Enter title and URL
3. Click "Add Link"

### **Reorder Menu Items**
1. Click and hold drag handle (⋮⋮)
2. Drag item to new position
3. Release to drop

### **Edit Labels**
1. Click on menu item label
2. Type new text
3. Click outside or press Enter

### **Edit External URLs**
1. Click on URL (external links only)
2. Type new URL
3. Click outside or press Enter

### **Remove Items**
1. Click "×" button
2. Confirm removal

### **Style the Menu**
1. Scroll to "Menu Styling" section
2. Adjust sliders and colors
3. Watch live preview update
4. Click "Save Settings" when done

### **Save Changes**
1. Click "Save Menu" button (top right)
2. Wait for success message
3. Page reloads with new menu

---

## 🔧 Technical Details

### **API Endpoints**

#### `save.php` - Save Menu Items
- **Method:** POST
- **Body:** JSON array of menu items
- **Response:** `{success: true, count: N}`

#### `handler.php` - Settings API
- **GET:** Load current settings
- **POST:** Save new settings
- **Response:** `{success: true, settings: {...}}`

### **Data Format**

#### Menu Items (menu_items.json)
```json
[
  {
    "title": "Page Title",
    "url": "/page.php?p=slug",
    "slug": "slug",
    "type": "page"
  },
  {
    "title": "External Link",
    "url": "https://example.com",
    "slug": "external-123456",
    "type": "external"
  }
]
```

#### Menu Settings (menu_settings.json)
```json
{
  "menu_bg_color": "#000000",
  "menu_bg_opacity": 0.95,
  "menu_item_bg_color": "#1a1a1a",
  "menu_item_hover_bg_color": "#007bff",
  "menu_font_color": "#ffffff",
  "menu_font_hover_color": "#ffffff",
  "menu_font_family": "Arial, sans-serif",
  "menu_font_size": 16,
  "menu_font_weight": 400,
  "menu_font_style": "normal"
}
```

### **Browser Support**
- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+

---

## 🎨 Customization

### **Add Custom Fonts**
Edit `index.php` around line 287:
```php
$fonts = [
    'Your Font, fallback' => 'Display Name',
    // ... add more fonts
];
```

### **Adjust Slider Ranges**
Edit `index.php`:
- Font Size: `min="10" max="32"`
- Font Weight: `min="100" max="900" step="100"`
- Opacity: `min="0" max="1" step="0.05"`

### **Modify Colors**
All colors use standard hex format (#RRGGBB) and HTML5 color pickers.

---

## 🔒 Security

### **Input Validation**
- All user input is sanitized
- HTML entities escaped
- JSON validation on all endpoints
- URL format validation for external links

### **File Permissions**
```bash
chmod 755 /admin/data/menu
chmod 644 /admin/data/menu/*.json
```

### **Backup System**
- Automatic backups on every save
- Timestamp format: `menu_items.backup.YmdHis.json`
- Located in `/admin/data/menu/`

---

## 📝 Migration Notes

### **From Old Location**
Old files in `/admin/data/`:
- `menu_items.json` → `/admin/data/menu/menu_items.json`
- `menu_settings.json` → `/admin/data/menu/menu_settings.json`

The new system creates these automatically on first run.

---

## 🐛 Troubleshooting

### **Menu won't save**
- Check file permissions on `/admin/data/menu/`
- Verify JSON is valid
- Check browser console for errors

### **Settings won't update**
- Clear browser cache
- Check `handler.php` has execute permissions
- Verify JSON format in settings file

### **Drag & drop not working**
- Ensure JavaScript is enabled
- Try different browser
- Check for JavaScript errors in console

### **Preview not updating**
- Hard refresh (Ctrl+F5)
- Verify all color pickers have valid values
- Check JavaScript console for errors

---

## 🔄 Updates & Maintenance

### **Version History**
- **2025.10.24.2100** - Initial enhanced version
  - Complete rewrite
  - Added inline editing
  - Added font manager
  - Added live preview
  - Reorganized data structure

### **Future Enhancements**
- Sub-menu support
- Icon upload per item
- Advanced animations
- Theme presets
- Import/export functionality

---

## 👥 Credits

Built for a legacy PHP/JS/HTML environment.

**Technologies:**
- Vanilla JavaScript (ES6+)
- PHP 7.4+
- HTML5
- CSS3 (Grid, Flexbox)

---

## 📄 License

Apache License 2.0 — see the LICENSE and NOTICE files at the repository root.

---

## 📞 Support

For issues or questions, check:
1. Browser console for errors
2. PHP error logs
3. File permissions
4. JSON file validity

**Happy Menu Managing! 🎉**
