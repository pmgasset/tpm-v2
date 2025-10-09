function init()
    m.top.functionName = "execute"
end function

sub execute()
    uri = m.top.uri
    if type(uri) <> "String" or Len(uri) = 0 then
        m.top.error = { message: "Missing request URI." }
        return
    end if

    method = m.top.method
    if type(method) <> "String" or Len(method) = 0 then
        method = "GET"
    end if
    method = UCase(method)

    transfer = CreateObject("roUrlTransfer")
    transfer.SetCertificatesFile("common:/certs/ca-bundle.crt")
    transfer.InitClientCertificates()
    transfer.EnableEncodings(true)
    transfer.RetainBodyOnError(true)
    transfer.SetURL(uri)
    transfer.AddHeader("Accept", "application/json")

    token = m.top.token
    if type(token) = "String" and Len(token) > 0 then
        transfer.AddHeader("X-Roku-Token", token)
    end if

    response = invalid
    status = 0

    if method = "POST" or method = "PUT" or method = "PATCH" then
        body = m.top.body
        if type(body) <> "String" then
            body = ""
        end if
        transfer.AddHeader("Content-Type", "application/json")

        if method = "POST" then
            response = transfer.PostFromString(body)
        else if method = "PUT" then
            response = transfer.PutFromString(body)
        else if method = "PATCH" then
            transfer.AddHeader("X-HTTP-Method-Override", "PATCH")
            response = transfer.PostFromString(body)
        end if
    else if method = "DELETE" then
        response = transfer.Delete()  ' returns boolean
        if response = true then
            response = ""
        end if
    else
        response = transfer.GetToString()
    end if

    status = transfer.GetResponseCode()
    m.top.status = status

    if type(response) = "String" then
        m.top.responseText = response
    else
        m.top.responseText = ""
    end if

    if status >= 200 and status < 300 then
        parsed = invalid
        if type(response) = "String" and Len(response) > 0 then
            parsed = ParseJSON(response)
        end if

        if type(parsed) = "roAssociativeArray" or type(parsed) = "roArray" then
            m.top.response = parsed
        else
            m.top.response = {}
        end if
    else
        errorInfo = { status: status }
        if type(response) = "String" and Len(response) > 0 then
            errorInfo.message = response
        else
            errorInfo.message = "Request failed"
        end if
        m.top.error = errorInfo
    end if
end sub
