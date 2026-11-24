 # Eventlijn hosting readiness

This is a technical readiness release, not an operational employee application.

1. Create a private writable directory outside the web document root (0700), dedicated to Eventlijn.
2. Configure EVENTLIJN_DATA_DIR to its absolute path in the hosting PHP environment. Do not place credentials in GitHub.
3. Upload EventStore.php and health.php together into /eventlijn/api/ on sharon.life. Do not replace any Sharon or Log files.
4. Open https://sharon.life/eventlijn/api/health.php. HTTP 200 with storage configured indicates the private directory is available. HTTP 503 means configuration needs attention.
5. Run php tests/storage.php in a PHP 8.1+ development environment before using the storage layer.

No employee data may be entered yet. Account verification, authorization, email, encryption at rest, backup and workflow are not included in this readiness release.
