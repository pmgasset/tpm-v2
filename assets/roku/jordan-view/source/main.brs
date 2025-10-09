sub main()
    print "[JordanView] main() starting"

    screen = CreateObject("roSGScreen")
    port = CreateObject("roMessagePort")
    screen.SetMessagePort(port)

    config = GetAppConfig()

    print "[JordanView] Loaded configuration"
    if config.LookupCI("apiBaseUrl") <> invalid then
        print "[JordanView] API base URL: " + toSafeString(config.apiBaseUrl)
    end if
    if config.LookupCI("propertyId") <> invalid then
        print "[JordanView] Property ID: " + toSafeString(config.propertyId)
    end if
    if config.LookupCI("propertyName") <> invalid then
        print "[JordanView] Property Name: " + toSafeString(config.propertyName)
    end if

    scene = screen.CreateScene("JordanViewScene")
    scene.config = config

    screen.Show()

    print "[JordanView] Screen shown; waiting for events"

    while true
        msg = wait(0, port)
        if type(msg) = "roSGScreenEvent" then
            if msg.isScreenClosed() then
                print "[JordanView] Screen closed"
                exit while
            end if
        end if
    end while
end sub

function toSafeString(value as dynamic) as String
    if type(value) = "String" then return value
    if type(value) = "Integer" or type(value) = "LongInteger" or type(value) = "Float" or type(value) = "Double" then
        result = Str(value)
        return result.Trim()
    end if
    return ""
end function
