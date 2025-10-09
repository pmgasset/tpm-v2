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
    if FileExists(configPath)
        json = ReadAsciiFile(configPath)
    else
        samplePath = "pkg:/config/app-config.sample.json"
        if FileExists(samplePath)
            json = ReadAsciiFile(samplePath)
        end if
    end if

    if type(json) = "String" and Len(json) > 0
        parsed = ParseJSON(json)
        if type(parsed) = "roAssociativeArray"
            for each key in parsed
                config[key] = parsed[key]
            end for
        end if
    end if

    if config.apiBaseUrl = ""
        config.apiBaseUrl = "https://example.com/wp-json/gms/v1"
    end if

    if config.title = ""
        config.title = "Jordan View"
    end if

    return config
end function
