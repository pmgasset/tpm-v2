sub init()
    m.hero = m.top.findNode("hero")
    m.heroOverlay = m.top.findNode("heroOverlay")
    m.logo = m.top.findNode("logo")
    m.titleLabel = m.top.findNode("title")
    m.guestNameLabel = m.top.findNode("guestName")
    m.staySummaryLabel = m.top.findNode("staySummary")
    m.doorCodeLabel = m.top.findNode("doorCodeLabel")
    m.doorCodeValue = m.top.findNode("doorCode")
    m.bookingReferenceLabel = m.top.findNode("bookingReference")
    m.upcomingLabel = m.top.findNode("upcoming")
    m.mediaRow = m.top.findNode("mediaRow")
    m.statusLabel = m.top.findNode("status")
    m.spinner = m.top.findNode("spinner")
    m.errorLabel = m.top.findNode("error")
    m.checkoutButton = m.top.findNode("checkoutButton")

    m.mediaRow.visible = false

    m.checkoutButton.observeField("buttonSelected", "onCheckoutSelected")

    m.apiTask = CreateObject("roSGNode", "ApiRequestTask")
    m.apiTask.observeField("response", "onDashboardLoaded")
    m.apiTask.observeField("error", "onDashboardFailed")

    m.checkoutTask = CreateObject("roSGNode", "ApiRequestTask")
    m.checkoutTask.observeField("response", "onCheckoutResponse")
    m.checkoutTask.observeField("error", "onCheckoutFailed")

    m.currentData = invalid
    m.currentConfig = invalid
    m.checkoutEndpoint = ""
    m.isLoading = false
    m.isCheckingOut = false

    m.top.observeField("config", "onConfigChanged")
end sub

sub onConfigChanged()
    config = m.top.config
    if type(config) <> "roAssociativeArray" then return

    m.currentConfig = config

    if config.LookupCI("title") <> invalid and type(config.title) = "String" then
        m.titleLabel.text = config.title
    else
        m.titleLabel.text = "Jordan View"
    end if

    loadDashboard()
end sub

sub loadDashboard()
    if m.currentConfig = invalid then return

    baseUrl = m.currentConfig.LookupCI("apiBaseUrl")
    if type(baseUrl) <> "String" or Len(baseUrl) = 0 then
        m.errorLabel.text = "Missing API base URL configuration."
        m.errorLabel.visible = true
        return
    end if

    uri = baseUrl.Trim()
    if Right(uri, 1) <> "/" then
        uri = uri + "/"
    end if
    uri = uri + "roku/dashboard"

    query = []
    propertyId = m.currentConfig.LookupCI("propertyId")
    if type(propertyId) = "String" and Len(propertyId) > 0 then
        query.Push("property_id=" + encodeComponent(propertyId))
    end if
    propertyName = m.currentConfig.LookupCI("propertyName")
    if type(propertyName) = "String" and Len(propertyName) > 0 then
        query.Push("property_name=" + encodeComponent(propertyName))
    end if
    mediaTag = m.currentConfig.LookupCI("mediaTag")
    if type(mediaTag) = "String" and Len(mediaTag) > 0 then
        query.Push("media_tag=" + encodeComponent(mediaTag))
    end if

    if query.Count() > 0 then
        uri = uri + "?" + joinArray(query, "&")
    end if

    token = m.currentConfig.LookupCI("rokuToken")
    if type(token) <> "String" then token = ""

    m.spinner.visible = true
    m.errorLabel.visible = false
    m.statusLabel.text = "Refreshing Jordan View experience…"
    m.mediaRow.visible = false

    m.apiTask.uri = uri
    m.apiTask.method = "GET"
    m.apiTask.token = token
    m.apiTask.body = ""
    m.apiTask.control = "run"

    m.isLoading = true
end sub

sub onDashboardLoaded()
    m.spinner.visible = false
    m.isLoading = false

    data = m.apiTask.response
    if type(data) <> "roAssociativeArray" then
        m.errorLabel.text = "Unexpected response from API."
        m.errorLabel.visible = true
        return
    end if

    if data.LookupCI("success") <> invalid and data.success = false then
        message = data.LookupCI("message")
        if type(message) = "String" then
            m.errorLabel.text = message
        else
            m.errorLabel.text = "Unable to load guest information."
        end if
        m.errorLabel.visible = true
        return
    end if

    m.errorLabel.visible = false
    m.currentData = data

    if data.LookupCI("branding") <> invalid and type(data.branding) = "roAssociativeArray" then
        applyBranding(data.branding)
    end if

    renderReservation(data.LookupCI("currentReservation"))
    renderUpcoming(data.LookupCI("upcomingReservation"))
    renderMedia(data.LookupCI("media"))

    meta = data.LookupCI("meta")
    if type(meta) = "roAssociativeArray" and meta.LookupCI("generatedAt") <> invalid then
        m.statusLabel.text = "Last updated " + meta.generatedAt
    else
        m.statusLabel.text = "Connected"
    end if
end sub

sub applyBranding(branding as object)
    logoUrl = branding.LookupCI("logoUrl")
    if type(logoUrl) = "String" and Len(logoUrl) > 0 then
        m.logo.uri = logoUrl
        m.logo.visible = true
    else
        m.logo.visible = false
    end if

    title = branding.LookupCI("title")
    if type(title) = "String" and Len(title) > 0 then
        m.titleLabel.text = title
    end if

    primaryColor = branding.LookupCI("primaryColor")
    if type(primaryColor) = "String" and Len(primaryColor) > 0 then
        colorValue = normalizeColor(primaryColor)
        if colorValue <> 0 then
            labelNode = m.checkoutButton.GetChild(0)
            if labelNode <> invalid then
                labelNode.color = colorValue
            end if
        end if
    end if
end sub

sub renderReservation(reservation as dynamic)
    if type(reservation) <> "roAssociativeArray" then
        m.guestNameLabel.text = "No guest currently checked in"
        m.staySummaryLabel.text = "Jordan View is ready for arrival."
        m.doorCodeLabel.visible = false
        m.doorCodeValue.visible = false
        m.doorCodeValue.text = ""
        m.bookingReferenceLabel.text = ""
        m.checkoutButton.visible = false
        m.checkoutEndpoint = ""
        return
    end if

    guest = reservation.LookupCI("guestName")
    if type(guest) = "String" and Len(guest) > 0 then
        m.guestNameLabel.text = guest
    else
        m.guestNameLabel.text = "Welcome"
    end if

    summary = reservation.LookupCI("staySummary")
    if type(summary) = "String" then
        m.staySummaryLabel.text = summary
    else
        checkin = reservation.LookupCI("checkin")
        checkout = reservation.LookupCI("checkout")
        m.staySummaryLabel.text = formatStayFallback(checkin, checkout)
    end if

    doorCode = reservation.LookupCI("doorCode")
    if type(doorCode) = "String" and Len(doorCode) > 0 then
        m.doorCodeLabel.visible = true
        m.doorCodeValue.visible = true
        m.doorCodeValue.text = doorCode
    else
        m.doorCodeLabel.visible = false
        m.doorCodeValue.visible = false
        m.doorCodeValue.text = ""
    end if

    bookingRef = reservation.LookupCI("bookingReference")
    if type(bookingRef) = "String" and Len(bookingRef) > 0 then
        m.bookingReferenceLabel.text = "Booking Reference: " + bookingRef
    else
        m.bookingReferenceLabel.text = ""
    end if

    actions = reservation.LookupCI("actions")
    showCheckout = false
    endpoint = ""
    if type(actions) = "roAssociativeArray" then
        if actions.LookupCI("canCheckout") <> invalid then
            canCheckout = actions.canCheckout
            if type(canCheckout) = "Boolean" then
                showCheckout = canCheckout
            else if type(canCheckout) = "String" then
                showCheckout = LCase(canCheckout) = "true"
            end if
        end if
        if actions.LookupCI("checkoutEndpoint") <> invalid then
            endpoint = actions.checkoutEndpoint
        end if
    end if

    m.checkoutEndpoint = endpoint
    if showCheckout and Len(endpoint) > 0 then
        m.checkoutButton.visible = true
        m.checkoutButton.SetFocus(true)
    else
        m.checkoutButton.visible = false
    end if

    statusLabel = reservation.LookupCI("statusLabel")
    if type(statusLabel) = "String" and Len(statusLabel) > 0 then
        m.statusLabel.text = statusLabel
    end if

    updateHeroArtwork()
end sub

sub renderUpcoming(upcoming as dynamic)
    if type(upcoming) <> "roAssociativeArray" then
        m.upcomingLabel.text = ""
        return
    end if

    guest = upcoming.LookupCI("guestName")
    checkin = upcoming.LookupCI("checkin")
    dateText = ""
    if type(checkin) = "roAssociativeArray" and checkin.LookupCI("dateLabel") <> invalid then
        dateText = checkin.dateLabel
        if checkin.LookupCI("timeLabel") <> invalid and Len(checkin.timeLabel) > 0 then
            dateText = dateText + " at " + checkin.timeLabel
        end if
    end if

    if type(guest) = "String" and Len(guest) > 0 then
        if Len(dateText) > 0 then
            m.upcomingLabel.text = "Next arrival: " + guest + " on " + dateText
        else
            m.upcomingLabel.text = "Next arrival: " + guest
        end if
    else if Len(dateText) > 0 then
        m.upcomingLabel.text = "Next arrival: " + dateText
    else
        m.upcomingLabel.text = ""
    end if
end sub

sub renderMedia(media as dynamic)
    if type(media) <> "roArray" or media.Count() = 0 then
        m.mediaRow.content = CreateObject("roSGNode", "ContentNode")
        m.mediaRow.visible = false
        return
    end if

    content = CreateObject("roSGNode", "ContentNode")
    row = content.CreateChild("ContentNode")
    row.title = "Jordan View"

    for each item in media
        if type(item) <> "roAssociativeArray" then
            continue for
        end if

        node = row.CreateChild("ContentNode")
        thumbnail = item.LookupCI("thumbnail")
        url = item.LookupCI("url")
        title = item.LookupCI("title")
        caption = item.LookupCI("caption")

        if type(thumbnail) = "String" and Len(thumbnail) > 0 then
            node.HDPosterUrl = thumbnail
            node.FHDPosterUrl = thumbnail
        else if type(url) = "String" and Len(url) > 0 then
            node.HDPosterUrl = url
            node.FHDPosterUrl = url
        end if

        if type(title) = "String" then node.title = title
        if type(caption) = "String" then node.shortDescriptionLine1 = caption
        if type(url) = "String" then node.url = url
        if item.LookupCI("type") <> invalid then node.contentType = item.type
    end for

    m.mediaRow.content = content
    m.mediaRow.visible = true

    heroCandidate = media[0]
    if type(heroCandidate) = "roAssociativeArray" then
        mediaType = heroCandidate.LookupCI("type")
        heroUrl = heroCandidate.LookupCI("url")
        if type(heroUrl) = "String" and Len(heroUrl) > 0 and (mediaType = invalid or mediaType = "image") then
            m.hero.uri = heroUrl
        end if
    end if
end sub

sub updateHeroArtwork()
    if m.currentData = invalid then return

    currentHero = m.hero.uri
    if type(currentHero) = "String" and Len(currentHero) > 0 then
        return
    end if

    media = m.currentData.LookupCI("media")
    if type(media) = "roArray" and media.Count() > 0 then
        candidate = media[0]
        if type(candidate) = "roAssociativeArray" then
            heroUrl = candidate.LookupCI("url")
            mediaType = candidate.LookupCI("type")
            if type(heroUrl) = "String" and Len(heroUrl) > 0 and (mediaType = invalid or mediaType = "image") then
                m.hero.uri = heroUrl
                return
            end if
        end if
    end if

    ' No media provided – fall back to logo if available
    if m.logo.visible and type(m.logo.uri) = "String" and Len(m.logo.uri) > 0 then
        m.hero.uri = m.logo.uri
    end if
end sub

sub onDashboardFailed()
    m.spinner.visible = false
    m.isLoading = false

    errorInfo = m.apiTask.error
    message = "Unable to load guest information."
    if type(errorInfo) = "roAssociativeArray" then
        if errorInfo.LookupCI("message") <> invalid and type(errorInfo.message) = "String" then
            message = errorInfo.message
        else if errorInfo.LookupCI("status") <> invalid then
            statusValue = errorInfo.status
            statusText = ""
            if type(statusValue) = "Integer" or type(statusValue) = "LongInteger" or type(statusValue) = "Float" or type(statusValue) = "Double" then
                statusText = Str(statusValue)
                statusText = statusText.Trim()
            else if type(statusValue) = "String" then
                statusText = statusValue
            end if
            if Len(statusText) > 0 then
                message = message + " (" + statusText + ")"
            end if
        end if
    end if

    m.errorLabel.text = message
    m.errorLabel.visible = true
end sub

sub onCheckoutSelected()
    if m.checkoutEndpoint = "" or m.isCheckingOut then return

    token = ""
    if m.currentConfig <> invalid then
        tokenValue = m.currentConfig.LookupCI("rokuToken")
        if type(tokenValue) = "String" then token = tokenValue
    end if

    if Len(token) = 0 then
        m.statusLabel.text = "Roku token not configured."
        return
    end if

    m.isCheckingOut = true
    m.spinner.visible = true
    m.statusLabel.text = "Checking guest out…"

    m.checkoutTask.uri = m.checkoutEndpoint
    m.checkoutTask.method = "POST"
    m.checkoutTask.token = token
    m.checkoutTask.body = "{}"
    m.checkoutTask.control = "run"
end sub

sub onCheckoutResponse()
    m.spinner.visible = false
    m.isCheckingOut = false

    response = m.checkoutTask.response
    if type(response) = "roAssociativeArray" then
        message = response.LookupCI("message")
        if type(message) = "String" then
            m.statusLabel.text = message
        else
            m.statusLabel.text = "Guest checked out."
        end if

        reservation = response.LookupCI("reservation")
        if type(reservation) = "roAssociativeArray" then
            renderReservation(reservation)
        end if
    else
        m.statusLabel.text = "Guest checked out."
    end if

    loadDashboard()
end sub

sub onCheckoutFailed()
    m.spinner.visible = false
    m.isCheckingOut = false

    errorInfo = m.checkoutTask.error
    message = "Unable to complete checkout."
    if type(errorInfo) = "roAssociativeArray" then
        if errorInfo.LookupCI("message") <> invalid and type(errorInfo.message) = "String" then
            message = errorInfo.message
        else if errorInfo.LookupCI("status") <> invalid then
            statusValue = errorInfo.status
            statusText = ""
            if type(statusValue) = "Integer" or type(statusValue) = "LongInteger" or type(statusValue) = "Float" or type(statusValue) = "Double" then
                statusText = Str(statusValue)
                statusText = statusText.Trim()
            else if type(statusValue) = "String" then
                statusText = statusValue
            end if
            if Len(statusText) > 0 then
                message = message + " (" + statusText + ")"
            end if
        end if
    end if

    m.statusLabel.text = message
end sub

function onKeyEvent(key as String, press as Boolean) as Boolean
    if not press then return false

    if key = "options" or key = "rewind" then
        loadDashboard()
        return true
    end if

    return false
end function

function formatStayFallback(checkin as dynamic, checkout as dynamic) as String
    if type(checkin) <> "roAssociativeArray" or type(checkout) <> "roAssociativeArray" then return ""

    cIn = ""
    if checkin.LookupCI("dateLabel") <> invalid then
        cIn = checkin.dateLabel
        if checkin.LookupCI("timeLabel") <> invalid and Len(checkin.timeLabel) > 0 then
            cIn = cIn + " at " + checkin.timeLabel
        end if
    end if

    cOut = ""
    if checkout.LookupCI("dateLabel") <> invalid then
        cOut = checkout.dateLabel
        if checkout.LookupCI("timeLabel") <> invalid and Len(checkout.timeLabel) > 0 then
            cOut = cOut + " at " + checkout.timeLabel
        end if
    end if

    if Len(cIn) > 0 and Len(cOut) > 0 then
        return cIn + " – " + cOut
    end if

    return cIn
end function

function encodeComponent(value as String) as String
    url = CreateObject("roUrlTransfer")
    return url.Escape(value)
end function

function joinArray(items as object, separator as String) as String
    if type(items) <> "roArray" or items.Count() = 0 then return ""

    result = ""
    for each item in items
        if Len(result) > 0 then
            result = result + separator
        end if
        result = result + item
    end for

    return result
end function

function normalizeColor(colorString as String) as Integer
    trimmed = colorString.Trim()
    if Len(trimmed) = 0 then return 0

    if Left(trimmed, 1) = "#" and Len(trimmed) = 7 then
        hex = Mid(trimmed, 2)
        return val("&hFF" + hex)
    else if Left(trimmed, 2) = "0x" and Len(trimmed) = 10 then
        hexValue = Mid(trimmed, 3)
        return val("&h" + hexValue)
    end if

    return 0
end function
