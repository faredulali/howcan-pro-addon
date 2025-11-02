jQuery(document).ready(function ($) {

    function refreshDemoStatus() {
        $.post(howcan_demo_ajax.ajax_url, {
            action: "howcan_get_active_demo",
            nonce: howcan_demo_ajax.nonce
        }, function (response) {
            if (response.success) {
                let active = response.data.active;
                $(".howcan-demo-card").each(function () {
                    const demo = $(this).data("demo");
                    const btn = $(this).find(".howcan-import-btn");

                    if (active === demo) {
                        $(this).addClass("active-demo");
                        btn.prop("disabled", true).text("✅ Activated");
                    } else {
                        $(this).removeClass("active-demo");
                        btn.prop("disabled", false).text("⚡ Import Demo");
                    }
                });
            }
        });
    }

    refreshDemoStatus();

    $(".howcan-import-btn").on("click", function () {
        var btn = $(this);
        var demo = btn.data("demo");
        var card = btn.closest(".howcan-demo-card");
        var originalText = btn.text();

        if (!confirm("⚠️ This will remove old demo data and import new. Continue?")) return;

        btn.prop("disabled", true).text("⏳ Starting Import...");
        card.find(".import-status").remove();
        card.append(`
            <div class="import-status" style="margin-top:15px;">
                <div class="progress-bar-bg" style="background:#eee;border-radius:6px;height:10px;width:100%;overflow:hidden;">
                    <div class="progress-bar-fill" style="background:#0073aa;width:0%;height:100%;transition:width 0.3s;"></div>
                </div>
                <div class="progress-text" style="margin-top:6px;font-weight:500;">Progress: 0%</div>
            </div>
        `);

        let progress = 0;
        const bar = card.find(".progress-bar-fill");
        const text = card.find(".progress-text");

        const interval = setInterval(() => {
            if (progress < 95) {
                progress += Math.floor(Math.random() * 5) + 1;
                bar.css("width", progress + "%");
                text.text("Progress: " + progress + "%");
            }
        }, 300);

        $.post(howcan_demo_ajax.ajax_url, {
            action: "howcan_import_demo",
            nonce: howcan_demo_ajax.nonce,
            demo: demo
        })
        .done(function (response) {
            clearInterval(interval);
            if (response.success) {
                bar.css("width", "100%");
                text.text("Progress: 100%");
                setTimeout(() => {
                    card.find(".import-status").append("<div style='color:green;margin-top:10px;'>✅ " + response.data.message + "</div>");
                    btn.text("✅ Activated").prop("disabled", true);
                    refreshDemoStatus();

                    setTimeout(() => {
                        if (confirm("🎉 " + response.data.message + "\n\n👉 View site now?")) {
                            window.open(response.data.site_url, "_blank");
                        }
                    }, 600);
                }, 500);
            } else {
                card.find(".import-status").append("<div style='color:red;margin-top:10px;'>❌ " + response.data + "</div>");
                btn.prop("disabled", false).text(originalText);
            }
        })
        .fail(function () {
            clearInterval(interval);
            card.find(".import-status").append("<div style='color:red;margin-top:10px;'>❌ AJAX Error</div>");
            btn.prop("disabled", false).text(originalText);
        });
    });

    $("#howcan-reset-all").on("click", function () {
        var btn = $(this);
        if (!confirm("⚠️ This will remove all demo data. Continue?")) return;
        btn.prop("disabled", true).text("⏳ Resetting...");
        $.post(howcan_demo_ajax.ajax_url, {
            action: "howcan_reset_all_demos",
            nonce: howcan_demo_ajax.nonce
        })
        .done(function (response) {
            alert(response.data);
            refreshDemoStatus();
            btn.prop("disabled", false).text("⚠️ Reset All Demos");
        });
    });
});