-- KEYS: job, owner, processing. ARGV: pid.
-- Replays the payload and ownership reads that led here: a release republishes
-- its delivery under the original pid, so a redelivery can re-create both keys
-- between those reads and this call, and dropping the entry then would strand
-- a live claim off the processing list.
local actual = redis.call('TYPE', KEYS[3]).ok
if actual ~= 'none' and actual ~= 'list' then return redis.error_reply('WRONGTYPE queue prune') end
if redis.call('EXISTS', KEYS[1]) == 1 or redis.call('EXISTS', KEYS[2]) == 1 then return 0 end
return redis.call('LREM', KEYS[3], 1, ARGV[1])
