-- Claim keys and matching ownership tokens. Renewal never recreates expired claims.
for i, key in ipairs(KEYS) do
    if redis.call('GET', key) == ARGV[i + 1] then redis.call('EXPIRE', key, ARGV[1]) end
end
return 1
