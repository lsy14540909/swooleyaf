--
-- Created by IntelliJ IDEA.
-- User: jw
-- Date: 2018/3/12 0012
-- Time: 12:31
-- To change this template use File | Settings | File Templates.
--

local configs = require("sywafconfigs")
local sytool = require("sytool")
local sywaftool = require("sywaftool")
local ngxMatch = ngx.re.find
local ngxUnescapeUri = ngx.unescape_uri

local function writeWafLog(msgType, msgContent, rule)
    local msgs = {}
    table.insert(msgs, ngx.localtime())
    table.insert(msgs, sytool.getClientIp())
    table.insert(msgs, ngx.var.server_name .. ngx.var.request_uri)
    table.insert(msgs, msgType)
    table.insert(msgs, '"' .. rule .. '"')
    table.insert(msgs, msgContent)
    local logFile = configs.DirLog .. ngx.today() .. '_attack.log'
    sytool.log(logFile, table.concat(msgs, ' '))
end

local function sendErrorRsp(status, msgType)
    ngx.status = status
    if msgType == 'json' then
        ngx.header.content_type = "application/json;charset=UTF-8"
        ngx.say(configs.ErrRspContentJson)
    else
        ngx.header.content_type = "text/html;charset=UTF-8"
        ngx.say(configs.ErrRspContentHtml)
    end

    ngx.exit(ngx.status)
end

local function checkFileExt(ext)
    local extStr = string.lower(ext)
    if configs.BlackFileExts[extStr] ~= nil then
        writeWafLog('File-Ext', 'invalid file ext', extStr)
        sendErrorRsp(ngx.HTTP_FORBIDDEN, 'html')
    end
end

local function checkPostArgs(data)
    if data ~= "" and #configs['BlackPostArgs'] > 0 then
        for _, rule in pairs(configs['BlackPostArgs']) do
            if ngxMatch(ngxUnescapeUri(data), rule, "isjo") then
                writeWafLog('Post-Args', data, rule)
                sendErrorRsp(ngx.HTTP_FORBIDDEN, 'html')
            end
        end
    end
end

local function checkHeader()
    if ngx.var.http_Acunetix_Aspect or ngx.var.http_X_Scan_Memo then
        sendErrorRsp(ngx.HTTP_CLOSE, 'html')
    end
end

local function checkIp()
    local ip = sytool.getClientIp()
    if configs.WhiteIps[ip] ~= nil then
        return
    end

    if configs.BlackIps[ip] ~= nil then
        sendErrorRsp(ngx.HTTP_FORBIDDEN, 'html')
    end
end

local function checkUri()
    local nowUri = ngx.var.uri
    if configs.StatusWhiteUri and #configs['WhiteUris'] > 0 then
        for _, rule in pairs(configs['WhiteUris']) do
            if ngxMatch(nowUri, rule, "isjo") then
                return
            end
        end
    end

    if configs.StatusBlackUri and #configs['BlackUris'] > 0 then
        for _, rule in pairs(configs['BlackUris']) do
            if ngxMatch(nowUri, rule, "isjo") then
                writeWafLog('Uri', nowUri, rule)
                sendErrorRsp(ngx.HTTP_FORBIDDEN, 'html')
            end
        end
    end
end

local function checkUserAgent()
    if #configs['BlackUserAgents'] > 0 then
        local ua = ngx.var.http_user_agent
        if ua ~= nil then
            for _, rule in pairs(configs['BlackUserAgents']) do
                if ngxMatch(ua, rule, "isjo") then
                    writeWafLog('User-Agent', ua, rule)
                    sendErrorRsp(ngx.HTTP_FORBIDDEN, 'html')
                end
            end
        end
    end
end

local function checkGetArgs()
    if #configs['BlackGetArgs'] > 0 then
        local nowTable = {}
        local nowArgs = ngx.req.get_uri_args()
        for key, val in pairs(nowArgs) do
            local valStr = val
            if type(val) == 'table' then
                local et = {}
                for k, v in pairs(val) do
                    if v == true then
                        table.insert(et, "")
                    else
                        table.insert(et, v)
                    end
                end
                valStr = table.concat(et, " ")
            end
            if valStr and type(valStr) ~= "boolean" then
                table.insert(nowTable, valStr)
            end
        end

        for eKey, eVal in pairs(nowTable) do
            for _, rule in pairs(configs['BlackGetArgs']) do
                if ngxMatch(ngxUnescapeUri(eVal), rule, "isjo") then
                    writeWafLog('Get-Args', eVal, rule)
                    sendErrorRsp(ngx.HTTP_FORBIDDEN, 'html')
                end
            end
        end
    end
end

local function checkCookie()
    local ck = ngx.var.http_cookie
    if configs.StatusCookie and #configs['BlackCookies'] > 0 and ck then
        for _, rule in pairs(configs['BlackCookies']) do
            if ngxMatch(ck, rule, "isjo") then
                writeWafLog('Cookie', ck, rule)
                sendErrorRsp(ngx.HTTP_FORBIDDEN, 'html')
            end
        end
    end
end

local function checkPost()
    local ngxReqMethod = ngx.req.get_method()
    if configs.StatusPost and ngxReqMethod == "POST" then
        local reqBoundary = sywaftool.getReqBoundary()
        if reqBoundary then
            local sock, err = ngx.req.socket()
            if not sock then
                return
            end
            ngx.req.init_body(131072)
            sock:settimeout(0)

            local chunkSize = 4096
            local contentLength = tonumber(ngx.req.get_headers()['content-length'])
            if contentLength < chunkSize then
                chunkSize = contentLength
            end

            local contentSize = 0
            while contentSize < contentLength do
                local data, err, partial = sock:receive(chunkSize)
                local eData = data or partial
                if not eData then
                    return
                end
                ngx.req.append_body(eData)
                checkPostArgs(eData)

                contentSize = contentSize + string.len(eData)
                local fileInfo = ngxMatch(eData, [[Content-Disposition: form-data;(.+)filename="(.+)\\.(.*)"]], 'ijo')
                if fileInfo then
                    checkFileExt(fileInfo[3])
                elseif ngxMatch(eData, "Content-Disposition:", 'isjo') then
                    checkPostArgs(eData)
                end

                local leftSize = contentLength - contentSize
                if leftSize < chunkSize then
                    chunkSize = leftSize
                end
            end
            ngx.req.finish_body()
        else
            ngx.req.read_body()
            local nowArgs = ngx.req.get_post_args()
            if not nowArgs then
                return
            end
            for key, val in pairs(nowArgs) do
                local eData = val
                if type(val) == "table" then
                    if type(val[1]) == "boolean" then
                        return
                    end
                    eData = table.concat(val, ", ")
                end
                if eData and type(eData) ~= "boolean" then
                    checkPostArgs(eData)
                    checkPostArgs(key)
                end
            end
        end
    end
end

local module = {}
module.tokenSecret = 'jb6hNP'

function module.checkWaf()
    checkHeader()
    checkIp()
    checkUri()
    checkUserAgent()
    checkGetArgs()
    checkCookie()
    checkPost()
end

function module.checkCCDeny(tag)
    local httpRefer = ngx.var.http_referer
    local hostTag = 'WhiteHosts' .. tag
    if httpRefer ~= nil and configs[hostTag] and #configs[hostTag] > 0 then
        for _, rule in pairs(configs[hostTag]) do
            if ngxMatch(httpRefer, rule, "isjo") then
                return
            end
        end
    end

    local ccCacheTag = 'sywafcc' .. tag
    local ccCache = ngx.shared[ccCacheTag]
    local ccCountTag = 'CCCount' .. tag
    local ccSecondsTag = 'CCSeconds' .. tag
    if ccCache ~= nil and configs[ccCountTag] and configs[ccSecondsTag] then
        local token = tostring(ngx.crc32_short(ngx.var.remote_addr))
        local reqNum,_ = ccCache:get(token)
        if reqNum then
            if reqNum < configs[ccCountTag] then
                ccCache:incr(token, 1)
            else
                sendErrorRsp(ngx.HTTP_OK, 'json')
            end
        else
            ccCache:set(token, 1, configs[ccSecondsTag])
        end
    end
end

return module