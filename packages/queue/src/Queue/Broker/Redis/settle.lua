local function settle(KEYS, ARGV)
    -- KEYS: claim, job, processing, processing counter, outcome counter, failed/dead.
    -- ARGV: token, pid, outcome (0 failure, 1 success, 2 release).
    local types = {'string', 'string', 'list', 'string', 'string', 'list'}
    for i, kind in ipairs(types) do
        local actual = redis.call('TYPE', KEYS[i]).ok
        if actual ~= 'none' and actual ~= kind then return redis.error_reply('WRONGTYPE queue settlement') end
    end
    for i = 4, 5 do
        local value = redis.call('GET', KEYS[i])
        if value and not string.match(value, '^%-?%d+$') then return redis.error_reply('Invalid queue counter') end
    end
    if redis.call('GET', KEYS[1]) ~= ARGV[1] then return 0 end
    redis.call('DEL', KEYS[1])
    local removed = redis.call('LREM', KEYS[3], 1, ARGV[2])
    if removed == 0 then return 0 end
    if ARGV[3] == '2' then
        local raw = redis.call('GET', KEYS[2])
        if raw then redis.call('RPUSH', KEYS[6], raw) end
        redis.call('DEL', KEYS[2])
    elseif ARGV[3] == '1' then
        redis.call('DEL', KEYS[2])
    else
        redis.call('LPUSH', KEYS[6], ARGV[2])
    end
    redis.call('DECR', KEYS[4])
    if ARGV[3] ~= '2' then redis.call('INCR', KEYS[5]) end
    return 1
end
local results = {}
for i = 1, #KEYS, 6 do
    local keys, args = {}, {}
    for j = 0, 5 do keys[j + 1] = KEYS[i + j] end
    local offset = ((i - 1) / 6) * 3
    for j = 1, 3 do args[j] = ARGV[offset + j] end
    local ok, result = pcall(settle, keys, args)
    results[#results + 1] = ok and result or redis.error_reply(result)
end
return results
