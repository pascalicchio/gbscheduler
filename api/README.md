# GB Scheduler REST API

Simple read-only API for accessing academy metrics from your OpenClaw agent.

---

## 🔑 Setup

### 1. Generate API Key

```bash
# Generate a secure 64-character key
openssl rand -hex 32
```

### 2. Add Key to Configuration

Edit `api/config.php` and add your key:

```php
define('API_KEYS', [
    'your_generated_key_here' => 'OpenClaw Agent',
    'another_key_here' => 'Mobile App', // Add more as needed
]);
```

### 3. Store Key Securely

For OpenClaw integration:

```bash
# Create .env file for OpenClaw
echo "GB_SCHEDULER_API_KEY=your_generated_key_here" >> ~/.openclaw/.env

# Or export directly
export GB_SCHEDULER_API_KEY="your_generated_key_here"
```

---

## 📡 API Endpoints

Base URL: `https://gbscheduler.com/api/v1/` (or `http://gb-scheduler2.test/api/v1/` for local)

### 1. Academy Overview

Get member counts, revenue, and growth metrics for all locations.

**Endpoint:** `GET /api/v1/academies.php`

**Parameters:**
- `key` (required) - Your API key

**Example:**
```bash
curl "http://gb-scheduler2.test/api/v1/academies.php?key=YOUR_KEY"
```

**Response:**
```json
{
  "davenport": {
    "total_members": 156,
    "active_members": 156,
    "true_active": 129,
    "on_hold": 27,
    "new_this_month": 23,
    "churned_this_month": 29,
    "net_growth": -6,
    "mrr": 22047.67,
    "mrr_last_month": 18500.00,
    "mrr_growth": 19.18,
    "arm": 141.33,
    "retention_rate": 81.41,
    "churn_rate": 18.59,
    "last_updated": "2026-02-12T20:00:00-05:00"
  },
  "celebration": {
    ...
  },
  "generated_at": "2026-02-12T20:00:00-05:00",
  "data_as_of": "2026-02-12T20:00:00-05:00"
}
```

---

### 2. Revenue Snapshot

Get revenue metrics and projections for a specific location.

**Endpoint:** `GET /api/v1/revenue.php`

**Parameters:**
- `key` (required) - Your API key
- `location` (required) - `davenport` or `celebration`

**Example:**
```bash
curl "http://gb-scheduler2.test/api/v1/revenue.php?key=YOUR_KEY&location=davenport"
```

**Response:**
```json
{
  "location": "davenport",
  "mrr": 22047.67,
  "mrr_last_month": 18500.00,
  "growth_percent": 19.18,
  "projected_monthly": 22500.00,
  "projected_annual": 270000.00,
  "ytd_revenue": 65432.10,
  "avg_monthly_revenue": 32716.05,
  "period": {
    "current_month": "February 2026",
    "last_month": "January 2026",
    "year": "2026"
  },
  "generated_at": "2026-02-12T20:00:00-05:00"
}
```

---

### 3. Enrollment Trends

Get enrollment and churn trends over time.

**Endpoint:** `GET /api/v1/trends.php`

**Parameters:**
- `key` (required) - Your API key
- `location` (required) - `davenport` or `celebration`
- `period` (optional) - `7days`, `30days` (default), `90days`, or `1year`

**Example:**
```bash
curl "http://gb-scheduler2.test/api/v1/trends.php?key=YOUR_KEY&location=davenport&period=30days"
```

**Response:**
```json
{
  "location": "davenport",
  "period": "30days",
  "start_date": "2026-01-13",
  "end_date": "2026-02-12",
  "grouping": "day",
  "enrollments": [
    {"date": "2026-01-15", "count": 2},
    {"date": "2026-01-22", "count": 3},
    {"date": "2026-02-05", "count": 1}
  ],
  "churn": [
    {"date": "2026-01-18", "count": 1},
    {"date": "2026-02-03", "count": 2}
  ],
  "summary": {
    "total_enrollments": 23,
    "total_churn": 29,
    "net_growth": -6,
    "avg_enrollments_per_day": 0.77,
    "avg_churn_per_day": 0.97
  },
  "generated_at": "2026-02-12T20:00:00-05:00"
}
```

---

## 🔒 Security Features

### API Key Authentication
All endpoints require a valid API key via `?key=YOUR_KEY` parameter or `X-API-Key` header.

### Rate Limiting
- **Limit:** 100 requests per hour per API key
- **Response:** 429 Too Many Requests if exceeded

### Caching
- Responses are cached for 1 hour to improve performance
- Cache is automatically invalidated after TTL expires

### CORS
- Configured for cross-origin requests
- Adjust `CORS_ORIGIN` in `api/config.php` for production

### Logging
All API requests are logged to `logs/api.log` with:
- Timestamp
- Client IP
- Client name (from API key)
- Endpoint accessed

---

## 🧪 Testing

### Test Locally

```bash
# 1. Generate test key
TEST_KEY=$(openssl rand -hex 32)
echo "Test key: $TEST_KEY"

# 2. Add to api/config.php temporarily
# 'your_test_key' => 'Test Client'

# 3. Test academies endpoint
curl "http://gb-scheduler2.test/api/v1/academies.php?key=$TEST_KEY"

# 4. Test revenue endpoint
curl "http://gb-scheduler2.test/api/v1/revenue.php?key=$TEST_KEY&location=celebration"

# 5. Test trends endpoint
curl "http://gb-scheduler2.test/api/v1/trends.php?key=$TEST_KEY&location=celebration&period=7days"

# 6. Test authentication (should fail)
curl "http://gb-scheduler2.test/api/v1/academies.php?key=invalid_key"
# Should return: {"error":"Unauthorized - Invalid or missing API key"}
```

### Test with OpenClaw

Create a test script:

```bash
#!/bin/bash
# test_api.sh

export GB_SCHEDULER_API_KEY="your_key_here"

# Fetch academy data
METRICS=$(curl -s "http://gb-scheduler2.test/api/v1/academies.php?key=$GB_SCHEDULER_API_KEY")

echo "Academy Metrics:"
echo "$METRICS" | jq '.'

# Test with OpenClaw (if installed)
# openclaw message --agent main "Analyze this data: $METRICS"
```

---

## 🚀 Production Deployment

### 1. Update CORS Settings

Edit `api/config.php`:

```php
// Change from '*' to your specific domain
define('CORS_ORIGIN', 'https://yourdomain.com');
```

### 2. Use HTTPS

Ensure your server has SSL certificate installed.

### 3. Secure API Keys

- Never commit API keys to git
- Store in environment variables
- Rotate keys periodically

### 4. Monitor Logs

```bash
# Check API usage
tail -f logs/api.log

# Check for errors
tail -f logs/php_errors.log
```

### 5. Performance Tuning

Adjust cache settings in `api/config.php`:

```php
define('CACHE_TTL', 3600); // Increase for less frequent updates
define('RATE_LIMIT_REQUESTS', 200); // Adjust based on usage
```

---

## 📊 OpenClaw Integration Example

Create a nightly surprise script that uses real data:

```bash
#!/bin/bash
# nightly_surprise.sh

# Load API key from environment
source ~/.openclaw/.env

# Fetch academy metrics
METRICS=$(curl -s "https://gbscheduler.com/api/v1/academies.php?key=$GB_SCHEDULER_API_KEY")

# Send to OpenClaw agent
openclaw message --agent main << EOF
Execute nightly surprise from HEARTBEAT.md.

Here's today's academy data:
$METRICS

Analyze this data and provide specific insights about:
1. Enrollment trends (are we growing?)
2. Capacity concerns (any classes overcrowded?)
3. Revenue changes (MRR up or down?)
4. Retention issues (churn rate problems?)

Be specific. Use actual numbers. Make it actionable.
EOF
```

Make it executable and add to crontab:

```bash
chmod +x nightly_surprise.sh

# Run every night at 8pm
crontab -e
# Add: 0 20 * * * /path/to/nightly_surprise.sh
```

---

## 🐛 Troubleshooting

### "Unauthorized" Error

**Problem:** API returns 401 Unauthorized

**Solution:**
1. Verify key in `api/config.php` matches your request
2. Check key is passed correctly: `?key=YOUR_KEY`
3. Ensure no extra spaces or newlines in key

### "Rate limit exceeded" Error

**Problem:** Too many requests (429 error)

**Solution:**
1. Wait 1 hour for rate limit to reset
2. Increase limit in `api/config.php`
3. Check if multiple clients are using same key

### Empty Response / No Data

**Problem:** API returns valid JSON but empty data

**Solution:**
1. Check if CSV data has been uploaded to database
2. Verify location name matches exactly: `davenport` or `celebration`
3. Check database has data for the requested period

### Cache Issues

**Problem:** API shows stale data

**Solution:**
```bash
# Clear cache manually
rm -rf /tmp/gbscheduler_api_cache/*

# Or disable cache temporarily in api/config.php
define('CACHE_ENABLED', false);
```

---

## 📋 Implementation Checklist

✅ Phase 1: API Infrastructure
- [x] API directory structure created
- [x] Authentication system implemented
- [x] Rate limiting added
- [x] Caching system built
- [x] Error handling configured

✅ Phase 2: Core Endpoints
- [x] `/api/v1/academies.php` - Academy overview
- [x] `/api/v1/revenue.php` - Revenue metrics
- [x] `/api/v1/trends.php` - Enrollment trends

⏳ Phase 3: Next Steps (Optional)
- [ ] Generate production API key
- [ ] Test all endpoints with real data
- [ ] Integrate with OpenClaw nightly script
- [ ] Deploy to production server
- [ ] Set up monitoring/alerts

---

## 🎯 What's Next?

1. **Generate your API key:**
   ```bash
   openssl rand -hex 32
   ```

2. **Add it to `api/config.php`**

3. **Test the API:**
   ```bash
   curl "http://gb-scheduler2.test/api/v1/academies.php?key=YOUR_KEY"
   ```

4. **Integrate with OpenClaw** using the nightly surprise script example above

---

**Your API is ready to use!** 🚀

The API is lightweight, secure, and designed specifically for your OpenClaw agent to access real academy data. No more generic advice - now your agent can give you specific, data-driven insights!
