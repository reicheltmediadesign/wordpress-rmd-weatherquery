jQuery(document).ready(function($) {
    
    // Load stations
    $("#load-stations").on("click", function() {
        var button = $(this);
        var originalText = button.text();
        button.prop("disabled", true).text(rmd_wq_ajax.strings.loading);

        $.ajax({
            url: rmd_wq_ajax.ajax_url,
            type: "POST",
            data: {
                action: "rmd_wq_get_stations",
                nonce: rmd_wq_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    displayStations(response.data);
                } else {
                    // Check if it's a long debug message
                    var errorMsg = response.data;
                    if (errorMsg.length > 200) {
                        // Create a modal-like display for long error messages
                        var modal = $('<div>')
                            .css({
                                'position': 'fixed',
                                'top': '10%',
                                'left': '10%',
                                'width': '80%',
                                'height': '70%',
                                'background': 'white',
                                'border': '2px solid #dc3232',
                                'z-index': '9999',
                                'padding': '20px',
                                'overflow': 'auto',
                                'box-shadow': '0 0 20px rgba(0,0,0,0.5)'
                            })
                            .html('<h3>Debug Information</h3><pre style="white-space: pre-wrap; word-wrap: break-word;">' + errorMsg + '</pre><br><button class="button" onclick="$(this).parent().remove()">Schließen</button>');
                        $('body').append(modal);
                    } else {
                        alert(rmd_wq_ajax.strings.error + ": " + errorMsg);
                    }
                }
            },
            error: function() {
                alert(rmd_wq_ajax.strings.error);
            },
            complete: function() {
                button.prop("disabled", false).text(originalText);
            }
        });
    });
    
    function displayStations(stations) {
        var container = $("#stations-container");
        container.empty();
        
        if (!stations || stations.length === 0) {
            container.html("<p>Keine Stationen gefunden.</p>");
            return;
        }
        
        var html = "<div class=\"stations-grid\">";
        stations.forEach(function(station) {
            html += "<div class=\"station-item\" data-station-id=\"" + station.station_id + "\" data-station-name=\"" + station.station_name + "\">";
            html += "<h4>" + station.station_name + "</h4>";
            html += "<p>ID: " + station.station_id + "</p>";
            if (station.location) {
                html += "<p>Standort: " + station.location + "</p>";
            }
            if (station.device_model) {
                html += "<p><strong>Gerät:</strong> " + station.device_model + "</p>";
            }
            html += "<button type=\"button\" class=\"button button-primary select-station\">Station auswählen</button>";
            html += "</div>";
        });
        html += "</div>";
        
        container.html(html);
        
        // Station selection handler
        $(".select-station").on("click", function() {
            var stationItem = $(this).closest(".station-item");
            var stationId = stationItem.data("station-id");
            var stationName = stationItem.data("station-name");
            
            $("#selected-station-id").text(stationId);
            $("#selected-station-name").text(stationName);
            $("#shortcode-generator").show();
            
            updateShortcode();
        });
    }
    
    // Shortcode generator
    function updateShortcode() {
        var stationId = $("#selected-station-id").text();
        var mode = $("#shortcode-mode").val();
        var fieldSelection = $(".field-selection-type:checked").val();
        var displayMode = $("#display-mode").val();
        var showIcons = $("#show-icons").is(":checked");
        var iconColor = $("#icon-color").val();
        var showTimestamp = $("#show-timestamp").is(":checked");

        if (!stationId) {
            return;
        }

        var shortcode = '[wq mode="' + mode + '" station_id="' + stationId + '"';

        // Add display mode if not default (list)
        if (displayMode && displayMode !== 'list') {
            shortcode += ' display_mode="' + displayMode + '"';
        }

        // Add show icons option
        if (showIcons) {
            shortcode += ' show_icons="true"';

            // Add icon color if not default
            if (iconColor && iconColor !== '#62C626') {
                shortcode += ' icon_color="' + iconColor + '"';
            }
        }

        // Add show timestamp option
        if (showTimestamp) {
            shortcode += ' show_timestamp="true"';
        }

        if (fieldSelection === 'all') {
            shortcode += ' show_all_fields="true"';
        } else {
            var fields = [];
            $(".shortcode-field:checked").each(function() {
                fields.push($(this).val());
            });

            if (fields.length > 0) {
                shortcode += ' fields="' + fields.join(',') + '"';
            } else {
                // Default to temp if no fields selected
                shortcode += ' fields="temp"';
            }
        }

        shortcode += ']';
        $("#generated-shortcode").val(shortcode);
    }
    
    // Update shortcode on changes
    $("#shortcode-mode, .shortcode-field, .field-selection-type, #display-mode, #show-icons, #icon-color, #show-timestamp").on("change", updateShortcode);

    // Enable/disable specific fields based on selection
    $(".field-selection-type").on("change", function() {
        if ($(this).val() === 'specific') {
            $("#specific-fields input").prop("disabled", false);
            $("#specific-fields").css("opacity", "1");
        } else {
            $("#specific-fields input").prop("disabled", true);
            $("#specific-fields").css("opacity", "0.5");
        }
        updateShortcode();
    });

    // Show/hide icon color picker based on icon checkbox
    $("#show-icons").on("change", function() {
        if ($(this).is(":checked")) {
            $("#icon-color-container").show();
        } else {
            $("#icon-color-container").hide();
        }
    });

    // Copy shortcode to clipboard
    $("#copy-shortcode").on("click", function() {
        var shortcodeInput = $("#generated-shortcode");
        shortcodeInput.select();
        document.execCommand("copy");
        
        var button = $(this);
        var originalText = button.text();
        button.text(rmd_wq_ajax.strings.copied);
        setTimeout(function() {
            button.text(originalText);
        }, 2000);
    });

    // Connection test
    $("#test-connection").on("click", function() {
        var button = $(this);
        var resultsDiv = $("#test-results");

        button.prop("disabled", true).text("Teste Verbindung...");
        resultsDiv.html('<div style="padding: 10px; background: #f0f0f1; border-left: 4px solid #0073aa;">Verbindung wird getestet...</div>');

        $.ajax({
            url: rmd_wq_ajax.ajax_url,
            type: "POST",
            data: {
                action: "rmd_wq_test_connection",
                nonce: rmd_wq_ajax.nonce
            },
            success: function(response) {
                var html = '';

                if (response.success) {
                    html = '<div style="padding: 10px; background: #dff0d8; border-left: 4px solid #5cb85c; margin-bottom: 10px;">';
                    html += '<strong>✓ Verbindung erfolgreich!</strong><br>';
                    html += response.message;
                    html += '</div>';

                    if (response.stations && response.stations.length > 0) {
                        html += '<div style="padding: 10px; background: #f9f9f9; border: 1px solid #ddd;">';
                        html += '<strong>Gefundene Stationen:</strong><br>';
                        response.stations.forEach(function(station) {
                            html += '• ' + station.station_name + ' (ID: ' + station.station_id + ')<br>';
                        });
                        html += '</div>';
                    }
                } else {
                    html = '<div style="padding: 10px; background: #f2dede; border-left: 4px solid #d9534f; margin-bottom: 10px;">';
                    html += '<strong>✗ Verbindungsfehler</strong><br>';
                    html += response.error;
                    html += '</div>';

                    if (response.debug_info) {
                        html += '<div style="padding: 10px; background: #f9f9f9; border: 1px solid #ddd; margin-top: 10px;">';
                        html += '<strong>Debug-Informationen:</strong><br>';

                        if (response.debug_info.request) {
                            html += '<br><strong>Request Details:</strong><br>';
                            html += 'URL: ' + response.debug_info.request.url + '<br>';
                            html += 'Method: ' + response.debug_info.request.method + '<br>';
                        }

                        if (response.debug_info.response_body) {
                            html += '<br><strong>Response Body:</strong><br>';
                            html += '<pre style="background: #f5f5f5; padding: 5px; white-space: pre-wrap; word-wrap: break-word;">';
                            html += response.debug_info.response_body;
                            html += '</pre>';
                        }

                        if (response.debug_info.response_data) {
                            html += '<br><strong>Parsed Response Data:</strong><br>';
                            html += '<pre style="background: #f5f5f5; padding: 5px; white-space: pre-wrap; word-wrap: break-word;">';
                            html += JSON.stringify(response.debug_info.response_data, null, 2);
                            html += '</pre>';
                        }

                        if (response.debug_info.json_error) {
                            html += '<br><strong>JSON Error:</strong><br>';
                            html += response.debug_info.json_error;
                        }

                        html += '</div>';
                    }
                }

                resultsDiv.html(html);
            },
            error: function(xhr, status, error) {
                var errorMsg = '<div style="padding: 10px; background: #f2dede; border-left: 4px solid #d9534f;">';
                errorMsg += '<strong>AJAX-Fehler beim Testen der Verbindung</strong><br>';
                errorMsg += 'Status: ' + status + '<br>';
                errorMsg += 'Error: ' + error + '<br>';
                if (xhr.responseText) {
                    errorMsg += 'Response: ' + xhr.responseText;
                }
                errorMsg += '</div>';
                resultsDiv.html(errorMsg);
            },
            complete: function() {
                button.prop("disabled", false).text("Verbindung testen");
            }
        });
    });

    // Cache refresh
    $("#refresh-cache").on("click", function() {
        var button = $(this);
        var resultsDiv = $("#cache-results");

        button.prop("disabled", true).text("Aktualisiere Cache...");
        resultsDiv.html('<div style="padding: 10px; background: #f0f0f1; border-left: 4px solid #0073aa;">Cache wird aktualisiert...</div>');

        $.ajax({
            url: rmd_wq_ajax.ajax_url,
            type: "POST",
            data: {
                action: "rmd_wq_refresh_cache",
                nonce: rmd_wq_ajax.nonce
            },
            success: function(response) {
                var html = '';
                if (response.success) {
                    html = '<div style="padding: 10px; background: #dff0d8; border-left: 4px solid #5cb85c;">';
                    html += '<strong>✓ Erfolg!</strong><br>' + response.data.message;
                    html += '</div>';
                } else {
                    html = '<div style="padding: 10px; background: #f2dede; border-left: 4px solid #d9534f;">';
                    html += '<strong>✗ Fehler</strong><br>' + response.data;
                    html += '</div>';
                }
                resultsDiv.html(html);
            },
            error: function() {
                resultsDiv.html('<div style="padding: 10px; background: #f2dede; border-left: 4px solid #d9534f;">AJAX-Fehler beim Aktualisieren des Cache</div>');
            },
            complete: function() {
                button.prop("disabled", false).text("Cache aktualisieren");
            }
        });
    });

    // Cache clear
    $("#clear-cache").on("click", function() {
        if (!confirm("Möchten Sie wirklich den gesamten Cache leeren?")) {
            return;
        }

        var button = $(this);
        var resultsDiv = $("#cache-results");

        button.prop("disabled", true).text("Leere Cache...");
        resultsDiv.html('<div style="padding: 10px; background: #f0f0f1; border-left: 4px solid #0073aa;">Cache wird geleert...</div>');

        $.ajax({
            url: rmd_wq_ajax.ajax_url,
            type: "POST",
            data: {
                action: "rmd_wq_clear_cache",
                nonce: rmd_wq_ajax.nonce
            },
            success: function(response) {
                var html = '';
                if (response.success) {
                    html = '<div style="padding: 10px; background: #dff0d8; border-left: 4px solid #5cb85c;">';
                    html += '<strong>✓ Erfolg!</strong><br>' + response.data.message;
                    html += '</div>';
                } else {
                    html = '<div style="padding: 10px; background: #f2dede; border-left: 4px solid #d9534f;">';
                    html += '<strong>✗ Fehler</strong><br>' + response.data;
                    html += '</div>';
                }
                resultsDiv.html(html);
            },
            error: function() {
                resultsDiv.html('<div style="padding: 10px; background: #f2dede; border-left: 4px solid #d9534f;">AJAX-Fehler beim Leeren des Cache</div>');
            },
            complete: function() {
                button.prop("disabled", false).text("Cache leeren");
            }
        });
    });

});