# Quick Start Guide - HTML Page Importer

## Installation

1. The plugin is already installed at:
   `/wp-content/plugins/page-importer/`

2. **Activate the plugin:**
   - Go to WordPress Admin Dashboard
   - Navigate to Plugins → Installed Plugins
   - Find "HTML Page Importer"
   - Click "Activate"

## First Import

1. **Access the Importer:**
   - In WordPress Admin, look for "HTML Importer" in the left menu
   - Click to open the import interface

2. **Prepare Your HTML Files:**
   - Make sure each file has:
     - An `<h1>` tag for the title
     - A `<div class="page-content">` for the content
     - A `<small>` tag with the date (optional)

3. **Import Steps:**
   - Click "Select HTML Files"
   - Choose one or more .html files
   - Select Page Status (Draft recommended for first import)
   - Choose Page Author
   - Optionally select a Category
   - Click "Import Files"

4. **Review Results:**
   - Check the results summary
   - Click "Edit" to review imported pages
   - Make any necessary adjustments
   - Publish when ready

## Example HTML Structure

The plugin expects this structure (based on your Birmingham HTML files):

```html
<h1>An even warmer welcome at St James, Shirley</h1>

<div class="page-content">
    <p>St James' Church, Shirley have just replaced...</p>
    <p>More content here...</p>
</div>

<div><small> 6th January 2026</small></div>
```

## Testing

For your first import:
1. Start with just 1-2 files
2. Import as "Draft" status
3. Review the imported pages
4. Check that title, content, and date are correct
5. If everything looks good, import the rest

## Tips

- ✓ Import as drafts first to review
- ✓ The plugin removes inline styles and extra attributes automatically
- ✓ Images will maintain their src attributes
- ✓ You can import multiple files at once
- ✓ All imports are logged for tracking

## Support

If you encounter issues:
1. Check that HTML files have the required elements
2. Review error messages in the results
3. Check one file manually to verify structure
4. Ensure file size is under 10MB

---

You're ready to go! Activate the plugin and start importing.
