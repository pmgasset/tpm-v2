sub main()
    screen = CreateObject("roSGScreen")
    port = CreateObject("roMessagePort")
    screen.SetMessagePort(port)

    dofile("pkg:/source/AppConfig.brs")
    config = GetAppConfig()

    scene = screen.CreateScene("JordanViewScene")
    scene.config = config

    screen.Show()

    while true
        msg = wait(0, port)
        if type(msg) = "roSGScreenEvent" then
            if msg.isScreenClosed() then
                exit while
            end if
        end if
    end while
end sub
