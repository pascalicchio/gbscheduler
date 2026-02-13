# GB Scheduler API - Quick Start

## 🎉 Your API is Ready!

The GB Scheduler API has been built and tested. All endpoints are working correctly.

---

## 🔑 Your API Key

```
479e6d293ed94a4a341ef395482d2fa82129c8912508fa5733a017f23bc228df
```

**⚠️ IMPORTANT:** Keep this key secret! Don't commit it to public repositories.

---

## 🚀 Quick Test

### Test Locally (Right Now!)

```bash
# Set your API key
export GB_API_KEY="479e6d293ed94a4a341ef395482d2fa82129c8912508fa5733a017f23bc228df"

# 1. Get academy overview
curl "http://gb-scheduler2.test/api/v1/academies.php?key=$GB_API_KEY"

# 2. Get Celebration revenue
curl "http://gb-scheduler2.test/api/v1/revenue.php?key=$GB_API_KEY&location=celebration"

# 3. Get 30-day trends
curl "http://gb-scheduler2.test/api/v1/trends.php?key=$GB_API_KEY&location=celebration&period=30days"
```

### Save API Key for OpenClaw

```bash
# Add to OpenClaw environment
echo "GB_SCHEDULER_API_KEY=479e6d293ed94a4a341ef395482d2fa82129c8912508fa5733a017f23bc228df" >> ~/.openclaw/.env

# Or export directly
export GB_SCHEDULER_API_KEY="479e6d293ed94a4a341ef395482d2fa82129c8912508fa5733a017f23bc228df"
```

---

## 📊 What the API Returns

### Academy Overview
Returns for both Davenport and Celebration:
- Active members count
- Members on hold
- New members this month
- Churn this month
- MRR (Monthly Recurring Revenue)
- ARM (Average Revenue per Member)
- Retention/churn rates

**Current Data:**
- **Celebration:** 156 members, 129 true active, 5 net growth this month
- **Davenport:** No member data (need to upload members CSV)

### Revenue Metrics
- MRR (this month)
- Last month's revenue
- Growth percentage
- Projected annual revenue
- Year-to-date totals

### Trends
Daily or weekly enrollment and churn data with summary statistics.

---

## 🤖 OpenClaw Integration

### Create Nightly Surprise Script

```bash
#!/bin/bash
# ~/scripts/nightly_surprise.sh

# Load API key
export GB_SCHEDULER_API_KEY="479e6d293ed94a4a341ef395482d2fa82129c8912508fa5733a017f23bc228df"

# Fetch academy data
METRICS=$(curl -s "http://gb-scheduler2.test/api/v1/academies.php?key=$GB_SCHEDULER_API_KEY")

# Send to OpenClaw
openclaw message --agent main << EOF
Execute nightly surprise from HEARTBEAT.md.

Here's today's REAL academy data:
$METRICS

Give me a specific insight based on actual numbers. Look for:
- Growth trends (are we up or down?)
- Capacity issues (classes filling up?)
- Revenue changes (MRR movement?)
- Retention concerns (churn spike?)

Be specific. Use the numbers. Make it actionable.
EOF
```

Make it executable:
```bash
chmod +x ~/scripts/nightly_surprise.sh

# Test it
~/scripts/nightly_surprise.sh
```

### Add to Crontab (Optional)

```bash
# Run every night at 8pm
crontab -e

# Add this line:
0 20 * * * /Users/fillipe/scripts/nightly_surprise.sh
```

---

## 📁 What Was Created

```
api/
├── config.php          # API configuration (keys, rate limits, cache settings)
├── auth.php            # Authentication & helper functions
├── README.md           # Full API documentation
└── v1/
    ├── academies.php   # Academy overview endpoint
    ├── revenue.php     # Revenue metrics endpoint
    └── trends.php      # Enrollment trends endpoint
```

---

## 🔒 Security Features

✅ **API Key Authentication** - All endpoints require valid key
✅ **Rate Limiting** - 100 requests/hour per key
✅ **Caching** - 1-hour cache to reduce database load
✅ **Request Logging** - All requests logged to `logs/api.log`
✅ **Error Handling** - Proper HTTP status codes and error messages
✅ **CORS Support** - Ready for cross-origin requests

---

## 📋 Next Steps

### For Production Use

1. **Update CORS settings** in `api/config.php` (change from '*' to your domain)
2. **Enable HTTPS** on your production server
3. **Deploy to production** - upload api/ directory to gbscheduler.com
4. **Update URLs** - change from `gb-scheduler2.test` to `gbscheduler.com`
5. **Test production** endpoints
6. **Update OpenClaw** scripts with production URLs

### For Davenport Location

**Upload Davenport members CSV** to enable:
- Active member counts
- ARM calculations
- Retention metrics
- All member-based analytics

Without it, Davenport will show 0 members and limited data.

---

## 🐛 Troubleshooting

### "Unauthorized" Error
- Verify you're using the correct API key
- Check key is passed as `?key=YOUR_KEY`

### Empty Revenue (MRR = 0)
- This is normal if no payments recorded in February yet
- Check `/api/v1/revenue.php` for last month's data (should show $36,236)

### Davenport Shows 0 Members
- Expected - no members CSV uploaded yet
- Upload via upload_zenplanner.php to fix

### Cache Shows Stale Data
```bash
# Clear cache
rm -rf /tmp/gbscheduler_api_cache/*
```

---

## ✅ Tested & Working

All endpoints have been tested and are working correctly:

- ✅ `/api/v1/academies.php` - Returns data for both locations
- ✅ `/api/v1/revenue.php` - Returns revenue metrics
- ✅ `/api/v1/trends.php` - Returns enrollment trends

**Your API is ready for OpenClaw integration!** 🚀

---

## 📖 Full Documentation

For complete API documentation, see: `api/README.md`

For implementation details, see: `GB-SCHEDULER-API-GUIDE.md`

---

**Questions?** Test the endpoints above and verify the data matches your expectations!
