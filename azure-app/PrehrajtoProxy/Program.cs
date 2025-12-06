var builder = WebApplication.CreateBuilder(args);

// Add HttpClient for proxy calls
builder.Services.AddHttpClient();

var app = builder.Build();

// Serve static files from wwwroot
app.UseDefaultFiles();
app.UseStaticFiles();

// API endpoint for searching movies via Czech proxy
app.MapGet("/api/search", async (string q, IHttpClientFactory httpClientFactory) =>
{
    if (string.IsNullOrEmpty(q))
    {
        return Results.BadRequest(new { success = false, error = "Missing search query (q parameter)" });
    }

    try
    {
        var client = httpClientFactory.CreateClient();
        client.Timeout = TimeSpan.FromSeconds(30);
        
        // Call the Czech proxy search endpoint
        var proxyUrl = $"http://tumarsrobot.unas.cz/index.php?action=search&q={Uri.EscapeDataString(q)}";
        var response = await client.GetStringAsync(proxyUrl);
        
        return Results.Content(response, "application/json");
    }
    catch (Exception ex)
    {
        return Results.Json(new { success = false, error = ex.Message });
    }
});

// API endpoint that proxies video URL extraction to Czech server
app.MapGet("/api/proxy", async (string url, IHttpClientFactory httpClientFactory) =>
{
    if (string.IsNullOrEmpty(url))
    {
        return Results.BadRequest(new { success = false, error = "Missing url parameter" });
    }

    if (!url.Contains("prehraj.to"))
    {
        return Results.BadRequest(new { success = false, error = "URL must be from prehraj.to" });
    }

    try
    {
        var client = httpClientFactory.CreateClient();
        client.Timeout = TimeSpan.FromSeconds(30);
        
        // Call the Czech proxy to extract video URL
        var proxyUrl = $"http://tumarsrobot.unas.cz/index.php?url={Uri.EscapeDataString(url)}";
        var response = await client.GetStringAsync(proxyUrl);
        
        // Return the response directly - contains CDN videoUrl
        return Results.Content(response, "application/json");
    }
    catch (Exception ex)
    {
        return Results.Json(new { success = false, error = ex.Message });
    }
});

app.Run();
