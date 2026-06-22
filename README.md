# CQ Auto Featured Image + AI Translate

## v15 Highlights

- Fixes stuck background audit where Action Scheduler was queued but no batch progress was saved.
- Reduces audit batch size to 10 posts.
- Adds checkpoint saves during audit batches.
- Adds Throwable logging for audit worker failures.
- Adds Emergency Non-AJAX Audit Batch fallback.
- Keeps date-preservation protections from v12.
