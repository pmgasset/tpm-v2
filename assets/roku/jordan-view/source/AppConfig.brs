function GetAppConfig() as object
    config = {
        apiBaseUrl: "https://example.com/wp-json/gms/v1",
        rokuToken: "",
        propertyId: "",
        mediaTag: "",
        title: "Jordan View"
    }

    json = invalid
    configPath = "pkg:/config/app-config.json"
    if FileExists(configPath) then
        json = ReadAsciiFile(configPath)
    else
        samplePath = "pkg:/config/app-config.sample.json"
        if FileExists(samplePath) then
            json = ReadAsciiFile(samplePath)
        end if
    end if

    if type(json) = "String" and Len(json) > 0 then
        parsed = ParseJSON(json)
        if type(parsed) = "roAssociativeArray" then
            for each key in parsed
                config[key] = parsed[key]
            end for
        end if
    end if

    if config.apiBaseUrl = "" then
        config.apiBaseUrl = "https://example.com/wp-json/gms/v1"
    end if

    if config.title = "" then
        config.title = "Jordan View"
    end if

    return config
end function
