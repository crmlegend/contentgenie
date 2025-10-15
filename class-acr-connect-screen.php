<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class ACR_Connect_Screen {
  public static function render() {
    // Retrieve options, safely escaping values when used in HTML
    $status = get_option('acr_key_status','unknown');
    $api_key = get_option('acr_api_key','');
    
    // Status message and classes based on connection status
    $status_text = ( $status === 'active' ) ? 'Connected' : 'Action Required';
    $status_class = ( $status === 'active' ) ? 'bg-emerald-500' : 'bg-red-500';
    $status_icon = ( $status === 'active' ) ? 
        '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>' : 
        '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>';
    
    // Define the style block for the modern UI (using a dedicated style block for Tailwind classes)
    ?>
    <style>
      /* Ensure Tailwind is loaded. For production, these classes should be compiled. */
      @import url('https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css');
      
      /* CRITICAL FIX: Container styling for centering and shorter height */
      .acr-container {
        max-width: 900px; /* Reduced max-width for a more compact appearance */
        margin: 0 auto;
        padding-top: 20px; /* Reduced top padding */
        padding-bottom: 20px; /* Reduced bottom padding */
      }
      /* Custom gradient for the hero section, similar to the 10Web design */
      .acr-hero-bg {
        background-image: linear-gradient(135deg, #4F46E5 0%, #1D4ED8 100%);
      }
      /* Custom style for the score circle */
      .acr-score-circle {
          width: 150px;
          height: 150px;
          border-radius: 50%;
          background: radial-gradient(white 65%, transparent 66%), 
                      conic-gradient(#10B981 324deg, #D1D5DB 0deg); /* 90% progress is 324deg */
          display: flex;
          align-items: center;
          justify-content: center;
          font-weight: 700;
          font-size: 40px;
          color: #059669; /* Emerald-600 */
          box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
      }
      /* Hide the WP wrap padding to prevent double padding */
      .wrap { padding: 0 !important; }

      /* This is a simple trick to visually center the whole block vertically in the available space */
      .acr-wrapper-flex {
        display: flex;
        align-items: center;
        min-height: 70vh; /* Use 70% of the viewport height to approximate vertical centering */
      }
    </style>

    <div class="wrap acr-wrapper-flex">
      <div class="acr-container">
        
        <div class="bg-white shadow-xl rounded-lg overflow-hidden border border-gray-200">
            
            <div class="p-6 border-b border-gray-100 flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <span class="text-2xl font-extrabold text-blue-600">ContentAISEO</span>
                    <p class="text-sm text-gray-500 mt-1">AI Content Generation & Optimization</p>
                </div>
                <div class="inline-flex items-center rounded-full px-3 py-1 text-xs font-medium text-white ring-1 ring-inset <?php echo $status_class; ?>">
                    <?php echo $status_icon; ?>
                    <span class="ml-1">Status: <?php echo esc_html( $status_text ); ?></span>
                </div>
            </div>

            <div class="lg:flex p-8 gap-10">
                
                <div class="lg:w-7/12 space-y-8">
                    <h2 class="text-xl font-semibold text-gray-700 mb-6">Setup Your AI Content Workflow</h2>
                    
                    <div class="flex space-x-4">
                        <div class="flex flex-col items-center">
                            <div class="w-8 h-8 flex-shrink-0 <?php echo $status === 'active' ? 'bg-blue-600' : 'bg-gray-300'; ?> rounded-full flex items-center justify-center text-white font-bold text-sm">
                                <?php echo $status === 'active' ? '&#10003;' : '1'; ?>
                            </div>
                            <div class="flex-grow w-0.5 bg-gray-200"></div>
                        </div>

                        <div class="flex-1 -mt-1 p-4 border border-gray-200 rounded-lg bg-gray-50 shadow-sm">
                            <h3 class="text-lg font-semibold text-gray-800 mb-2">
                                1. Connect Your ContentAISEO Account
                            </h3>
                            <p class="text-sm text-gray-600 mb-4">
                                Sign up and paste your API key below to enable powerful AI content generation and optimization features.
                            </p>
                            
                            <form method="post">
                                <?php wp_nonce_field('acr_connect'); ?>
                                <label for="acr_api_key" class="text-sm font-medium text-gray-700 block mb-1">
                                    API Key
                                </label>
                                <input type="password" name="acr_api_key" id="acr_api_key" 
                                       class="w-full border border-gray-300 rounded-md p-2 text-sm focus:border-blue-500 focus:ring focus:ring-blue-200 focus:ring-opacity-50" 
                                       placeholder="Enter your API key" 
                                       value="<?php echo esc_attr( $api_key ); ?>">
                                
                                <div class="mt-4 flex space-x-3">
                                    <button class="button button-primary bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-full transition duration-150 shadow-md" 
                                            name="acr_connect" value="1">
                                        <?php echo $status === 'active' ? 'Update Key' : 'Connect Account'; ?>
                                    </button>
                                    <a class="button button-secondary text-blue-600 border border-blue-200 hover:bg-blue-50 py-2 px-4 rounded-full transition duration-150" 
                                       href="<?php echo esc_url( ACR_SUBSCRIBE_URL ); ?>" 
                                       target="_blank" rel="noopener">
                                        Get API Key / Subscribe
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>
                    
                    <div class="flex space-x-4">
                        <div class="flex flex-col items-center">
                            <div class="w-8 h-8 flex-shrink-0 bg-gray-300 rounded-full flex items-center justify-center text-gray-600 font-bold text-sm">
                                2
                            </div>
                        </div>

                        <div class="flex-1 -mt-4 p-4">
                            <h3 class="text-lg font-semibold text-gray-800 mb-2">
                                2. Start Generating High-Ranking Content
                            </h3>
                            <p class="text-sm text-gray-500">
                                Once connected, you can access the full ContentAISEO dashboard to start drafting SEO-optimized articles and replacers directly within your post editor.
                            </p>
                            </div>
                    </div>

                </div>

                <div class="lg:w-5/12 mt-8 lg:mt-0">
                    <div class="acr-hero-bg text-white p-6 rounded-xl shadow-lg">
                        <h3 class="text-xl font-bold mb-3">
                            Achieve 90+ SEO Scores Automatically
                        </h3>
                        <p class="text-sm opacity-90 mb-6">
                            ContentAISEO creates and optimizes content using cutting-edge AI to rank higher in search engines.
                        </p>
                        
                        <div class="flex items-center justify-center mb-6">
                            <div class="acr-score-circle">90+</div>
                        </div>

                        <div class="space-y-3">
                            <h4 class="text-base font-semibold border-b border-blue-400 pb-1 mb-2">
                                Key Benefits:
                            </h4>
                            <ul class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                                <li class="flex items-center">
                                    <span class="mr-2 text-green-300">&#10003;</span> High Keyword Density
                                </li>
                                <li class="flex items-center">
                                    <span class="mr-2 text-green-300">&#10003;</span> Plagiarism-Free Content
                                </li>
                                <li class="flex items-center">
                                    <span class="mr-2 text-green-300">&#10003;</span> Optimized Readability
                                </li>
                                <li class="flex items-center">
                                    <span class="mr-2 text-green-300">&#10003;</span> One-Click Publishing
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="p-4 border-t border-gray-100 text-center text-xs text-gray-400">
                Thank you for choosing ContentAISEO. Need help? Check our documentation.
            </div>

        </div>
      </div>
    </div>
    <?php
  }
}