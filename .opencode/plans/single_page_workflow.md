# Single-Page Event Creation with Slide Upload

## Goal
Create a seamless workflow where users can name an event and immediately upload/classify slides on the same page, without navigating away.

## Current Flow (to replace)
1. Go to index.php
2. Fill event name/date → click "Create event"
3. Redirects to event.php?id=X#slides
4. User manually clicks "Slides" tab to upload

## New Flow
1. Go to index.php
2. Fill event name/date → click "Create event"
3. **Same page** — event is created, slide upload area appears below
4. Upload ZIP/images, classify slides (question/answer/promo), set difficulty per pair
5. Click "Save All Slides"
6. Redirect to event.php for further configuration (categories, contestants, etc.)

---

## Files to Create/Modify

### 1. `public/admin/actions/event_create_ajax.php` — New action
Handles AJAX event creation, returns JSON with new event_id:
```json
{
  "ok": true,
  "event_id": 123,
  "event_name": "Math Bowl 2024"
}
```

### 2. `public/admin/index.php` — Major rewrite
Replace the two-column layout with a stepped flow:

**Step 1: Create Event Form** (shown initially)
- Event name (required)
- Subtitle (optional)
- Event date (optional)
- "Create & Upload Slides" button

**Step 2: Slide Upload Area** (shown after event creation via AJAX)
- Reuses the existing batch upload UI from event.php
- Shows: "Event 'X' created! Now upload your slides."
- Same drag-drop zone, file inputs, progress bar
- Classification panel with type + difficulty dropdowns
- Save button redirects to event.php after success

### 3. `public/assets/index.js` — New JS file
Handle the stepped workflow:
- Capture form submit, send AJAX to event_create_ajax.php
- On success, hide form, show upload area with event_id
- Wire up the existing slide upload JS (or move it to shared code)

### 4. Update `public/assets/admin.js`
Extract the slide upload initialization into a reusable function so it can be called from both event.php and index.php.

---

## Implementation Details

### index.php structure:
```php
<div id="event-create-section">
    <h2>Create a new event</h2>
    <form id="event-create-form">
        <!-- name, subtitle, date fields -->
        <button type="submit">Create & Upload Slides</button>
    </form>
</div>

<div id="slide-upload-section" style="display:none;">
    <h2>Upload Slides for <span id="new-event-name"></span></h2>
    <input type="hidden" id="new-event-id" value="">
    <!-- Reuse existing slide upload UI -->
</div>
```

### JS flow:
```javascript
// Step 1: Create event via AJAX
document.getElementById('event-create-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const formData = new FormData(e.target);
    
    const response = await fetch('actions/event_create_ajax.php', {
        method: 'POST',
        body: formData
    });
    const data = await response.json();
    
    if (data.ok) {
        document.getElementById('new-event-id').value = data.event_id;
        document.getElementById('new-event-name').textContent = data.event_name;
        document.getElementById('event-create-section').style.display = 'none';
        document.getElementById('slide-upload-section').style.display = 'block';
        initSlideUpload(data.event_id); // Reuse existing function
    }
});

// Step 2: After saving slides, redirect to event page
// (modify existing save handler to redirect instead of reload)
```

### Changes to existing batch_save.php:
- Add a `redirect_url` parameter
- When present, return redirect URL instead of JSON
- Or: keep JSON response but update JS to redirect on success

---

## Key Considerations

1. **Reuse existing code**: The batch upload JS already works. Need to extract initialization to work standalone.
2. **CSRF tokens**: Must work across the AJAX call and subsequent uploads.
3. **Session persistence**: The batch_upload session storage already handles this.
4. **Error handling**: Show errors inline instead of redirecting.

---

## Execution Order
1. Create `event_create_ajax.php`
2. Modify `index.php` with stepped UI
3. Create/modify JS to handle the flow
4. Update existing slide upload JS to be callable from index.php
5. Test the complete workflow
